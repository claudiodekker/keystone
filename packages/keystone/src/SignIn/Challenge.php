<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\Pages\ChallengePage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\RateLimits\FailedAttempt;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCodeType;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * @internal
 */
class Challenge
{
    /**
     * Create the challenge.
     */
    public function __construct(
        protected AccountChanges $accountChanges,
        protected AccountLookup $accounts,
        protected SignInDecision $decision,
        protected Holdings $holdings,
        protected Limiter $limiter,
        protected Proofs $proofs,
        protected RecoveryCodeType $recoveryCodeType,
        protected Recorder $recorder,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected CredentialTypes $types,
        protected Config $config,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Find the type that answers the challenge by its name: a type offered there, or core's recovery codes.
     */
    public function type(string $name): ?CredentialType
    {
        if ($name === RecoveryCodeType::NAME) {
            return $this->recoveryCodeType;
        }

        return $this->types->find($name, Surface::CHALLENGE);
    }

    /**
     * Open the challenge of the session's pending sign-in: the second-factor types its account can answer with, then its recovery codes, the first one preselected.
     */
    public function open(): ChallengePage|NoPendingSignIn
    {
        return $this->floor->hold(
            fn () => $this->offer(),
            fn () => false,
        );
    }

    /**
     * Answer the challenge of the session's pending sign-in with the type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ThrottledException
     * @throws ValidationException
     */
    public function answer(CredentialType $type, array $input): SignedIn|Refused|LastRecoveryCodeKept|NoPendingSignIn
    {
        $pending = $this->session->pendingSignIn();
        $account = $this->resume($pending);

        if ($pending === null || $account === null) {
            return new NoPendingSignIn;
        }

        $validated = $this->validate($type, $input);

        return $this->floor->hold(
            fn () => $this->prove($type, $validated, $pending, $account),
            fn (SignedIn|Refused|LastRecoveryCodeKept $outcome) => $outcome instanceof SignedIn,
        );
    }

    /**
     * Cancel the session's pending sign-in, leaving the cancelled status for the sign-in page.
     */
    public function cancel(): void
    {
        $this->session->dropPendingSignIn();

        $this->session->leaveStatus(Status::SIGN_IN_CANCELLED);
    }

    /**
     * Get the page value of the challenge, or nothing pending when the session's pending sign-in no longer owes it.
     */
    protected function offer(): ChallengePage|NoPendingSignIn
    {
        $pending = $this->session->pendingSignIn();
        $account = $this->resume($pending);

        if ($pending === null || $account === null) {
            return new NoPendingSignIn;
        }

        $offered = array_diff($this->holdings->secondFactors($account), [$pending->firstFactor]);

        $types = collect($this->types->shapes(Surface::CHALLENGE))
            ->filter(fn (array $type) => in_array($type['name'], $offered, true))
            ->values()
            ->all();

        if ($this->holdings->holdsRecoveryCodes($account)) {
            $types[] = ['name' => RecoveryCodeType::NAME, 'shape' => InitiateShape::FORM->value];
        }

        return new ChallengePage(
            types: $types,
            preselected: $types[0]['name'] ?? null,
        );
    }

    /**
     * Validate the type's own fields, and get only those.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validate(CredentialType $type, array $input): array
    {
        return $this->validation->make($input, $type->rules(Surface::CHALLENGE))->validate();
    }

    /**
     * Sign in the held account when the type proves one of its second-factor credentials.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function prove(CredentialType $type, array $validated, PendingSignIn $pending, KeystoneUser $account): SignedIn|Refused|LastRecoveryCodeKept
    {
        $attempt = $this->limiter->takeFailedAttempt($type, Surface::CHALLENGE, $account);

        if ($type instanceof RecoveryCodeType) {
            return $this->spend($type, $validated, $pending, $account, $attempt);
        }

        $answer = $this->proofs->verify($type, Surface::CHALLENGE, $account, $validated, $attempt);

        if (! $this->decision->answers($answer->proof, $type, $pending, $account)) {
            return $this->proofs->refuse($account, $answer);
        }

        return $this->proofs->signIn($account, $answer, writer: $pending);
    }

    /**
     * Sign in the held account when the typed code is one of its recovery codes, spending it.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function spend(RecoveryCodeType $type, array $validated, PendingSignIn $pending, KeystoneUser $account, FailedAttempt $attempt): SignedIn|Refused|LastRecoveryCodeKept
    {
        $codes = UserRecoveryCode::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->get();

        $verification = new Verification(
            surface: Surface::CHALLENGE,
            input: $validated,
            credentials: $codes->map->toCredential()->values()->all(),
        );

        $answer = new Answer($type, Surface::CHALLENGE, $type->verify($verification), new Collection, $attempt);
        $code = $codes->find($answer->proof->credential);

        if ($code === null) {
            return $this->proofs->refuse($account, $answer);
        }

        try {
            $spent = $this->accountChanges->change($account, fn (AccountChange $change) => $this->spendUnlessLast($change, $code), $pending);
        } catch (AccountChangeRefusedException) {
            return $this->proofs->refuse($account, $answer);
        }

        if (! $spent) {
            return $this->keepLast($account, $answer);
        }

        return $this->proofs->signIn($account, $answer, writer: $pending);
    }

    /**
     * Spend the code, unless recovery codes are required and it is the account's last, and determine if it was spent.
     */
    protected function spendUnlessLast(AccountChange $change, UserRecoveryCode $code): bool
    {
        if ($this->config->boolean('keystone.require_recovery_codes') && $change->holdsLastRecoveryCode()) {
            return false;
        }

        $change->spendRecoveryCode($code);

        return true;
    }

    /**
     * Refuse the account's last recovery code, keeping it and its counted attempt, and record the refusal.
     */
    protected function keepLast(KeystoneUser $account, Answer $answer): LastRecoveryCodeKept
    {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $account,
            surface: $answer->surface,
            credentialType: $answer->type,
            reason: 'recovery-code.last_code',
        );

        return new LastRecoveryCodeKept;
    }

    /**
     * Get the account the pending sign-in still holds at the challenge, dropping a hold that ended and recording one voided.
     */
    protected function resume(?PendingSignIn $pending): ?KeystoneUser
    {
        if ($pending?->stage !== Stage::CHALLENGE) {
            return null;
        }

        $held = $this->decision->resume($pending);

        if ($held instanceof KeystoneUser) {
            return $held;
        }

        $this->session->dropPendingSignIn();

        if ($held === HoldEnded::VOIDED) {
            $this->recorder->record(
                SecurityEventType::SIGN_IN_VOIDED,
                account: $this->accounts->find($pending->account),
                reason: 'keystone.epoch',
            );
        }

        return null;
    }
}

<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\Pages\SudoPage;
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
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\Session\SudoInProgress;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * @internal
 */
class Sudo
{
    /**
     * Create the sudo replay.
     */
    public function __construct(
        protected AccountChanges $accountChanges,
        protected Recorder $recorder,
        protected SignInDecision $decision,
        protected Holdings $holdings,
        protected Limiter $limiter,
        protected Proofs $proofs,
        protected RecoveryCodeType $recoveryCodeType,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected CredentialTypes $types,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Find the type that answers the sudo-in-progress's step by its name: a type offered on the step's surface, or core's recovery codes at the second step.
     */
    public function type(string $name): ?CredentialType
    {
        $surface = $this->surface();

        if ($surface === Surface::CHALLENGE && $name === RecoveryCodeType::NAME) {
            return $this->recoveryCodeType;
        }

        return $this->types->find($name, $surface);
    }

    /**
     * Get the surface the session's sudo-in-progress's step verifies on, sign-in's when it holds none.
     */
    public function surface(): Surface
    {
        return $this->session->sudoInProgress()?->surface() ?? Surface::SIGN_IN;
    }

    /**
     * Open the session's sudo-in-progress at its step, the first type it offers preselected.
     *
     * @throws AuthenticationException
     */
    public function open(): SudoPage|NoSudoInProgress
    {
        $account = $this->account();
        $sudo = $this->session->sudoInProgress();

        if ($sudo === null) {
            return new NoSudoInProgress($this->session->intendedPath());
        }

        $types = $this->offer($sudo, $account);

        return new SudoPage(
            types: $types,
            preselected: $types[0]['name'] ?? null,
        );
    }

    /**
     * Answer the session's sudo-in-progress with the type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthenticationException
     * @throws ThrottledException
     * @throws ValidationException
     */
    public function answer(CredentialType $type, array $input): SudoGranted|SudoFirstFactorPassed|Refused|LastRecoveryCodeKept|NoSudoInProgress
    {
        $account = $this->account();
        $sudo = $this->session->sudoInProgress();

        if ($sudo === null) {
            return new NoSudoInProgress($this->session->intendedPath());
        }

        $validated = $this->validation->make($input, $type->rules($sudo->surface()))->validate();

        return $this->floor->hold(
            fn () => $this->prove($type, $validated, $sudo, $account),
            fn (SudoGranted|SudoFirstFactorPassed|Refused|LastRecoveryCodeKept $outcome) => $outcome instanceof SudoGranted || $outcome instanceof SudoFirstFactorPassed,
        );
    }

    /**
     * End the session's sudo, recording the end of a live grant, and leave the revoked status for the next page.
     *
     * @throws AuthenticationException
     */
    public function end(): void
    {
        $account = $this->account();

        if ($this->session->endSudo()) {
            $this->recorder->record(SecurityEventType::SUDO_REVOKED, account: $account);
        }

        $this->session->leaveStatus(Status::SUDO_REVOKED);
    }

    /**
     * Grant the session sudo when the type proves one of the account's credentials.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function prove(CredentialType $type, array $validated, SudoInProgress $sudo, KeystoneUser $account): SudoGranted|SudoFirstFactorPassed|Refused|LastRecoveryCodeKept
    {
        $surface = $sudo->surface();
        $attempt = $this->limiter->takeFailedAttempt($type, $surface, $account);
        $subnet = $this->session->subnet();

        if ($subnet === null) {
            return $this->proofs->refuseSudo($account, $type, $surface, 'keystone.unbindable_subnet');
        }

        if ($type instanceof RecoveryCodeType) {
            return $this->spend($type, $validated, $sudo, $account, $attempt, $subnet);
        }

        $answer = $this->proofs->verify($type, $surface, $account, $validated, $attempt);

        if (! $this->proves($answer, $sudo, $account)) {
            return $this->proofs->refuseSudo($account, $type, $surface, $answer->proof->reason);
        }

        if ($sudo->firstFactor === null && $this->decision->owed($account, $type) === Stage::CHALLENGE) {
            return $this->proofs->passSudoFirstFactor($account, $answer, $sudo);
        }

        return $this->proofs->grantSudo($account, $answer, $sudo, $subnet);
    }

    /**
     * Grant the session sudo when the typed code is one of the account's recovery codes, spending it unless it is the last.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function spend(RecoveryCodeType $type, array $validated, SudoInProgress $sudo, KeystoneUser $account, FailedAttempt $attempt, string $subnet): SudoGranted|Refused|LastRecoveryCodeKept
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
            return $this->proofs->refuseSudo($account, $type, Surface::CHALLENGE, $answer->proof->reason);
        }

        try {
            $spent = $this->accountChanges->change($account, fn (AccountChange $change) => $this->spendUnlessLast($change, $code), $sudo);
        } catch (AccountChangeRefusedException) {
            return $this->proofs->refuseSudo($account, $type, Surface::CHALLENGE, $answer->proof->reason);
        }

        if (! $spent) {
            $this->proofs->refuseSudo($account, $type, Surface::CHALLENGE, 'recovery-code.last_code');

            return new LastRecoveryCodeKept;
        }

        return $this->proofs->grantSudo($account, $answer, $sudo, $subnet);
    }

    /**
     * Spend the code unless it is the account's last, which is kept for account recovery, and determine if it was spent.
     */
    protected function spendUnlessLast(AccountChange $change, UserRecoveryCode $code): bool
    {
        if ($change->isLastRecoveryCode($code)) {
            return false;
        }

        $change->spendRecoveryCode($code);

        return true;
    }

    /**
     * Get the types the sudo-in-progress's step offers: the types serving sign-in the account holds, and at the second step the second factors it holds of another type than the first factor's, then its recovery codes.
     *
     * @return array<int, array{name: string, shape: string}>
     */
    protected function offer(SudoInProgress $sudo, KeystoneUser $account): array
    {
        $held = $sudo->firstFactor === null
            ? $this->holdings->signInTypes($account)
            : array_diff($this->holdings->secondFactors($account), [$sudo->firstFactor]);

        $types = collect($this->types->shapes($sudo->surface()))
            ->filter(fn (array $type) => in_array($type['name'], $held, true))
            ->values()
            ->all();

        if ($sudo->firstFactor !== null && $this->holdings->holdsRecoveryCodes($account)) {
            $types[] = ['name' => RecoveryCodeType::NAME, 'shape' => InitiateShape::FORM->value];
        }

        return $types;
    }

    /**
     * Determine if the answer proves the sudo-in-progress's step: a credential the account holds, and at the second step one of another type than the first factor's.
     */
    protected function proves(Answer $answer, SudoInProgress $sudo, KeystoneUser $account): bool
    {
        if ($sudo->firstFactor === null) {
            return $this->decision->proves($answer->proof, $answer->type, $account);
        }

        return $this->decision->answers($answer->proof, $answer->type, $sudo->firstFactor, $account);
    }

    /**
     * Get the signed-in account, refusing a guest.
     *
     * @throws AuthenticationException
     */
    protected function account(): KeystoneUser
    {
        return $this->session->account() ?? throw new AuthenticationException;
    }
}

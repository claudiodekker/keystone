<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\AlertRecipients;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\Pages\EnrollmentPage;
use ClaudioDekker\Keystone\Http\Pages\RecoveryCodesPage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCodeType;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * @internal
 */
class Enrollment
{
    /**
     * Create the enrollment.
     */
    public function __construct(
        protected AccountChanges $accountChanges,
        protected AccountLookup $accounts,
        protected AlertRecipients $alertRecipients,
        protected SignInDecision $decision,
        protected Holdings $holdings,
        protected Limiter $limiter,
        protected Proofs $proofs,
        protected RecoveryCodeType $recoveryCodeType,
        protected Recorder $recorder,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected CredentialTypes $types,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Find the type enrollment takes an answer of by its name: a type the account can enroll as its second factor, or core's recovery codes.
     */
    public function type(string $name): ?CredentialType
    {
        if ($name === RecoveryCodeType::NAME) {
            return $this->recoveryCodeType;
        }

        return collect($this->decision->enrollable())->first(fn (CredentialType $type) => $type->name() === $name);
    }

    /**
     * Open the step the session's pending sign-in owes next: the second factor, then the recovery codes.
     */
    public function open(): EnrollmentPage|RecoveryCodesPage|NoPendingSignIn
    {
        return $this->floor->hold(
            fn () => $this->offer(),
            fn () => false,
        );
    }

    /**
     * Answer the step the session's pending sign-in owes with the type and the submitted input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ThrottledException
     * @throws ValidationException
     */
    public function answer(CredentialType $type, array $input): SignedIn|Held|Refused|RecoveryCodeIncorrect|EnrollmentExpired|NoPendingSignIn
    {
        $pending = $this->session->pendingSignIn();
        $account = $this->resume($pending);

        if ($pending === null || $account === null) {
            return new NoPendingSignIn;
        }

        $validated = $this->validate($type, $input);

        return $this->floor->hold(
            fn () => $this->step($type, $validated, $pending, $account),
            fn (SignedIn|Held|Refused|RecoveryCodeIncorrect|EnrollmentExpired $outcome) => $outcome instanceof SignedIn || $outcome instanceof Held,
        );
    }

    /**
     * Cancel the session's pending sign-in, leaving the cancelled status for the sign-in page.
     */
    public function cancel(): void
    {
        $this->session->dropPendingSignIn();

        $this->session->leaveStatus(Status::ENROLLMENT_CANCELLED);
    }

    /**
     * Get the page value of the step the pending sign-in owes, or nothing pending when it no longer owes enrollment.
     */
    protected function offer(): EnrollmentPage|RecoveryCodesPage|NoPendingSignIn
    {
        $pending = $this->session->pendingSignIn();
        $account = $this->resume($pending);

        if ($pending === null || $account === null) {
            return new NoPendingSignIn;
        }

        if ($this->decision->owesSecondFactor($account)) {
            return $this->secondFactorPage($account);
        }

        $page = $this->ceremonyPage($this->recoveryCodeType, $account);

        return new RecoveryCodesPage($page['codes']);
    }

    /**
     * Get the page value of the second-factor step: every type the account can enroll, each with what its live ceremony shows, the first one preselected.
     */
    protected function secondFactorPage(KeystoneUser $account): EnrollmentPage
    {
        $enrollable = collect($this->decision->enrollable())
            ->keyBy(fn (CredentialType $type) => $type->name())
            ->all();

        $types = collect($this->types->shapes(Surface::ENROLLMENT))
            ->filter(fn (array $offered) => isset($enrollable[$offered['name']]))
            ->map(fn (array $offered) => [...$offered, 'ceremony' => $this->ceremonyOf($enrollable[$offered['name']], $account)])
            ->values()
            ->all();

        return new EnrollmentPage(
            types: $types,
            preselected: $types[0]['name'] ?? null,
        );
    }

    /**
     * Get what the page shows of the type's live ceremony on enrollment, or null when the type has nothing to start.
     *
     * @return array<string, mixed>|null
     */
    protected function ceremonyOf(CredentialType $type, KeystoneUser $account): ?array
    {
        if (! $type instanceof StartsCeremony) {
            return null;
        }

        return $this->ceremonyPage($type, $account);
    }

    /**
     * Get what the page shows of the type's live ceremony on enrollment, starting one when none is live.
     *
     * @return array<string, mixed>
     */
    protected function ceremonyPage(CredentialType&StartsCeremony $type, KeystoneUser $account): array
    {
        $label = $this->label($account);

        $page = $this->session->ceremonyPage($type, Surface::ENROLLMENT, $label);

        if ($page !== null) {
            return $page;
        }

        $ceremony = $this->session->startCeremony($type, Surface::ENROLLMENT) ?? [];

        return $type->page(Surface::ENROLLMENT, $ceremony, $label);
    }

    /**
     * Get the name the account signs in with, as an authenticator app labels its entry: its first address alerts go to.
     */
    protected function label(KeystoneUser $account): string
    {
        return $this->alertRecipients->of($account)[0] ?? (string) $account->getAuthIdentifier();
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
        return $this->validation->make($input, $type->rules(Surface::ENROLLMENT))->validate();
    }

    /**
     * Take the answer of the step the pending sign-in owes: the second factor while the account owes one, else the recovery codes.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function step(CredentialType $type, array $validated, PendingSignIn $pending, KeystoneUser $account): SignedIn|Held|Refused|RecoveryCodeIncorrect|EnrollmentExpired
    {
        if (! $this->decision->owesSecondFactor($account)) {
            return $type instanceof RecoveryCodeType
                ? $this->saveRecoveryCodes($type, (string) $validated['recovery_code'], $pending, $account)
                : new Refused;
        }

        if ($type instanceof RecoveryCodeType) {
            return new Refused;
        }

        return $this->enroll($type, $validated, $pending, $account);
    }

    /**
     * Store the second factor the type's live ceremony proves the user set up, then move on to the recovery codes or sign in.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ThrottledException
     */
    protected function enroll(CredentialType $type, array $validated, PendingSignIn $pending, KeystoneUser $account): SignedIn|Held|Refused|EnrollmentExpired
    {
        if ($type instanceof StartsCeremony && $this->session->ceremony($type, Surface::ENROLLMENT) === null) {
            return $this->expire($type);
        }

        $attempt = $this->limiter->takeFailedAttempt($type, Surface::ENROLLMENT, $account);
        $answer = $this->proofs->verify($type, Surface::ENROLLMENT, $account, $validated, $attempt);
        $secret = $answer->proof->enrolledSecret();

        if ($secret === null) {
            return $this->proofs->refuse($account, $answer);
        }

        try {
            $this->accountChanges->change($account, fn (AccountChange $change) => $this->addSecondFactor($change, $account, $type, $secret), $pending);
        } catch (AccountChangeRefusedException) {
            return $this->proofs->refuse($account, $answer);
        }

        $this->limiter->giveBack($attempt);

        return $this->complete($type, $pending, $account);
    }

    /**
     * Give the account the enrolled second factor, refusing when it holds one already, such as one another answer enrolled first.
     *
     * @throws AccountChangeRefusedException
     */
    protected function addSecondFactor(AccountChange $change, KeystoneUser $account, CredentialType $type, #[SensitiveParameter] string $secret): void
    {
        if ($this->holdings->holdsSecondFactor($account)) {
            throw AccountChangeRefusedException::secondFactorHeld();
        }

        $change->addCredential($type, $secret, Surface::ENROLLMENT);
    }

    /**
     * Store the staged recovery codes once the user typed one of them back, then sign in.
     */
    protected function saveRecoveryCodes(RecoveryCodeType $type, #[SensitiveParameter] string $typed, PendingSignIn $pending, KeystoneUser $account): SignedIn|Held|Refused|RecoveryCodeIncorrect|EnrollmentExpired
    {
        $ceremony = $this->session->ceremony($type, Surface::ENROLLMENT);

        if ($ceremony === null) {
            return $this->expire($type);
        }

        if (! $type->isStaged($ceremony, $typed)) {
            return new RecoveryCodeIncorrect;
        }

        try {
            $this->accountChanges->change($account, fn (AccountChange $change) => $change->saveRecoveryCodes($type->staged($ceremony)), $pending);
        } catch (AccountChangeRefusedException) {
            return new Refused;
        }

        return $this->complete($type, $pending, $account);
    }

    /**
     * Move the pending sign-in on to the recovery codes while the account owes them, else sign it in.
     */
    protected function complete(CredentialType $type, PendingSignIn $pending, KeystoneUser $account): SignedIn|Held
    {
        if ($this->decision->owesRecoveryCodes($account)) {
            $this->session->passSecondFactor($pending, $type);

            return new Held(Stage::ENROLLMENT);
        }

        $redirectTo = $this->session->signIn($account);

        $this->recorder->record(
            SecurityEventType::SIGNED_IN,
            account: $account,
            surface: Surface::ENROLLMENT,
            credentialType: $type,
        );

        return new SignedIn($redirectTo);
    }

    /**
     * Start the type's ceremony afresh in place of the one that ended.
     */
    protected function expire(CredentialType&StartsCeremony $type): EnrollmentExpired
    {
        $this->session->startCeremony($type, Surface::ENROLLMENT);

        return new EnrollmentExpired;
    }

    /**
     * Get the account the pending sign-in still holds at enrollment, dropping a hold that ended and recording one voided.
     */
    protected function resume(?PendingSignIn $pending): ?KeystoneUser
    {
        if ($pending?->stage !== Stage::ENROLLMENT) {
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

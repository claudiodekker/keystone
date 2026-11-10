<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\Pages\ChallengePage;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
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
        protected AccountLookup $accounts,
        protected SignInDecision $decision,
        protected Holdings $holdings,
        protected Limiter $limiter,
        protected Proofs $proofs,
        protected Recorder $recorder,
        protected SessionState $session,
        protected TimingFloor $floor,
        protected CredentialTypes $types,
        protected ValidationFactory $validation,
    ) {
        //
    }

    /**
     * Open the challenge of the session's pending sign-in: the second-factor types its account can answer with, the first one preselected.
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
    public function answer(CredentialType $type, array $input): SignedIn|Refused|NoPendingSignIn
    {
        $pending = $this->session->pendingSignIn();
        $account = $this->resume($pending);

        if ($pending === null || $account === null) {
            return new NoPendingSignIn;
        }

        $validated = $this->validate($type, $input);

        return $this->floor->hold(
            fn () => $this->prove($type, $validated, $pending, $account),
            fn (SignedIn|Refused $outcome) => $outcome instanceof SignedIn,
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
    protected function prove(CredentialType $type, array $validated, PendingSignIn $pending, KeystoneUser $account): SignedIn|Refused
    {
        $attempt = $this->limiter->takeFailedAttempt($type, Surface::CHALLENGE, $account);

        $answer = $this->proofs->verify($type, Surface::CHALLENGE, $account, $validated, $attempt);

        if (! $this->decision->answers($answer->proof, $type, $pending, $account)) {
            return $this->proofs->refuse($account, $answer);
        }

        return $this->proofs->signIn($account, $answer, writer: $pending);
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

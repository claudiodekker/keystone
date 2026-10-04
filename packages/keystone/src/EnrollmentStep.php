<?php

namespace ClaudioDekker\Keystone;

use LogicException;

/**
 * @internal
 */
abstract class EnrollmentStep
{
    /**
     * Create a new enrollment step instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Sign the pending sign-in in once its account, read afresh, owes nothing more, keep it at enrollment for what it still owes, or drop it when it now owes the challenge.
     */
    protected function proceed(string $enrolledType): Demand
    {
        $pending = $this->guard->pending();

        if ($pending === null) {
            return Demand::REFUSE;
        }

        $demand = (new SignInDecision)->next($pending);

        if ($demand === Demand::CHALLENGE) {
            $this->guard->forgetPending();

            return Demand::CHALLENGE;
        }

        if ($demand !== Demand::SIGN_IN) {
            return $demand;
        }

        try {
            $this->guard->signIn($pending->account);
        } catch (LogicException) {
            return Demand::REFUSE;
        }

        $this->recorder->record(
            SecurityEventType::SIGNED_IN,
            account: $pending->account,
            flow: Flow::ENROLLMENT->value,
            credentialType: $enrolledType,
        );

        return Demand::SIGN_IN;
    }

    /**
     * Record a refused enrollment answer for the account.
     */
    protected function recordRejected(PendingSignIn $pending, string $type, string $reason): void
    {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $pending->account,
            flow: Flow::ENROLLMENT->value,
            credentialType: $type,
            reason: $reason,
        );
    }
}

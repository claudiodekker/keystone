<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;

/**
 * @internal
 */
class EnrollmentEntry extends Entry
{
    /**
     * Create a new enrollment entry instance.
     */
    public function __construct(
        KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        parent::__construct($guard);
    }

    /**
     * Sign the pending sign-in in once its account, read afresh, owes nothing more, keep it at enrollment for what it still owes, or drop it when it now owes the challenge.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    public function make(): Passed
    {
        $pending = $this->guard->pending();

        if ($pending === null) {
            return new Passed(Demand::REFUSE);
        }

        $owed = (new SignInDecision)->next($pending);

        if ($owed === Demand::CHALLENGE) {
            $this->guard->forgetPending();
        }

        if ($owed !== Demand::SIGN_IN) {
            return new Passed($owed);
        }

        $passed = $this->signIn($pending->account, $pending->rememberMe, $pending->origin);

        if ($pending->origin->recordsCompletedEnrollment()) {
            $this->recorder->record(SecurityEventType::ENROLLMENT_COMPLETED, account: $pending->account, flow: Flow::ENROLLMENT->value);
        }

        return $passed;
    }
}

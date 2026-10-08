<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;

/**
 * @internal
 */
class ChallengeEntry extends Entry
{
    /**
     * Create a new challenge entry instance.
     */
    public function __construct(
        KeystoneGuard $guard,
        protected PendingSignIn $pending,
    ) {
        parent::__construct($guard);
    }

    /**
     * Sign in the pending sign-in that passed its challenge, or hold it on for the enrollment its account still owes, then forget the pending challenge its hold opened.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    public function make(): Passed
    {
        $account = $this->pending->account;

        $passed = (new SignInDecision)->owesEnrollment($account)
            ? $this->holdAtEnrollment()
            : $this->signIn($account, $this->pending->rememberMe);

        if ($this->pending->pendingChallengeId !== null) {
            rescue(fn () => (new PendingChallenges($account))->forget($this->pending->pendingChallengeId));
        }

        return $passed;
    }

    /**
     * Hold the pending sign-in on at enrollment, its second factor passed.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    protected function holdAtEnrollment(): Passed
    {
        $this->guard->passSecondFactor();

        return new Passed(Demand::ENROLLMENT, SecurityEventType::SIGN_IN_HELD, reason: 'keystone.enrollment');
    }
}

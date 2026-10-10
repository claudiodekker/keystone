<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
class RegistrationEntry extends Entry
{
    /**
     * Create a new registration entry instance.
     */
    public function __construct(
        KeystoneGuard $guard,
        protected Model&KeystoneUser $account,
        protected CredentialType $type,
        protected string $intendedUrl,
    ) {
        parent::__construct($guard);
    }

    /**
     * Sign in the account the registration just created, alerting nobody about the browser that created it, or hold its sign-in for the enrollment it owes.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    public function make(): Passed
    {
        return match ((new SignInDecision)->demand($this->account, $this->type)) {
            Demand::SIGN_IN => $this->signIn($this->account, RememberMe::NOT_ASKED, PendingOrigin::REGISTRATION),
            Demand::ENROLLMENT => $this->holdForEnrollment(),
            Demand::REFUSE => throw Barred::inactive(),
            Demand::CHALLENGE => throw new LogicException('A new account holds no second factor, so it never owes the challenge.'),
        };
    }

    /**
     * Hold the new account's sign-in for the enrollment it owes, with origin registration.
     *
     * @return Passed<Demand>
     */
    protected function holdForEnrollment(): Passed
    {
        $this->guard->hold(
            $this->account,
            firstFactor: $this->type->name(),
            stage: PendingStage::ENROLLMENT,
            intendedUrl: $this->intendedUrl,
            origin: PendingOrigin::REGISTRATION,
        );

        return new Passed(Demand::ENROLLMENT, SecurityEventType::SIGN_IN_HELD, reason: 'keystone.enrollment');
    }
}

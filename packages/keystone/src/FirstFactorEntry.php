<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class FirstFactorEntry extends Entry
{
    /**
     * Create a new first factor entry instance.
     */
    public function __construct(
        KeystoneGuard $guard,
        protected Model&KeystoneUser $account,
        protected CredentialType $type,
        protected string $intendedUrl,
        protected RememberMe $rememberMe,
    ) {
        parent::__construct($guard);
    }

    /**
     * Sign in the account whose first factor is proven, or hold its sign-in for the challenge or the enrollment it owes.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    public function make(): Passed
    {
        $owed = (new SignInDecision)->demand($this->account, $this->type);

        if ($owed === Demand::REFUSE) {
            throw Barred::inactive();
        }

        if ($owed === Demand::SIGN_IN) {
            return $this->signIn($this->account, $this->rememberMe);
        }

        $this->guard->hold(
            $this->account,
            firstFactor: $this->type->name(),
            stage: $owed === Demand::CHALLENGE ? PendingStage::CHALLENGE : PendingStage::ENROLLMENT,
            intendedUrl: $this->intendedUrl,
            rememberMe: $this->rememberMe,
        );

        return new Passed($owed, SecurityEventType::SIGN_IN_HELD, reason: "keystone.{$owed->value}");
    }
}

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class Entry
{
    /**
     * Create a new entry instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
    ) {
        //
    }

    /**
     * Enter an account whose first factor is proven: sign it in, or hold its sign-in for the challenge or the enrollment it owes.
     *
     * @return Passed<Demand>|null what the entry earned, or null for a barred account
     */
    public function afterFirstFactor(Model&KeystoneUser $account, CredentialType $type, string $intendedUrl, RememberMe $rememberMe): ?Passed
    {
        $owed = (new SignInDecision)->demand($account, $type);

        if ($owed === Demand::REFUSE) {
            return null;
        }

        if ($owed === Demand::SIGN_IN) {
            return $this->signIn($account, $rememberMe);
        }

        $stage = $owed === Demand::CHALLENGE ? PendingStage::CHALLENGE : PendingStage::ENROLLMENT;

        $this->guard->hold($account, firstFactor: $type->name(), stage: $stage, intendedUrl: $intendedUrl, rememberMe: $rememberMe);

        return new Passed($owed, SecurityEventType::SIGN_IN_HELD, reason: "keystone.{$owed->value}");
    }

    /**
     * Enter the account of a pending sign-in that passed its challenge: sign it in, or hold it on for the enrollment it still owes, then forget the pending challenge its hold opened.
     *
     * @return Passed<Demand>|null what the entry earned, or null for a barred account
     */
    public function afterChallenge(PendingSignIn $pending): ?Passed
    {
        $passed = (new SignInDecision)->owesEnrollment($pending->account)
            ? $this->holdAtEnrollment()
            : $this->signIn($pending->account, $pending->rememberMe);

        if ($passed !== null && $pending->pendingChallengeId !== null) {
            rescue(fn () => (new PendingChallenges($pending->account))->forget($pending->pendingChallengeId));
        }

        return $passed;
    }

    /**
     * Enter the account of a pending sign-in that enrolled its second factor, which counts as passing it.
     *
     * @return Passed<Demand>|null what the entry earned, or null for a barred account
     */
    public function afterSecondFactorEnrollment(): ?Passed
    {
        return $this->passSecondFactor() ? $this->afterEnrollment() : null;
    }

    /**
     * Enter the account of a pending sign-in that set up its recovery codes.
     *
     * @return Passed<Demand>|null what the entry earned, or null for a barred account
     */
    public function afterRecoveryCodeSetup(): ?Passed
    {
        return $this->afterEnrollment();
    }

    /**
     * Sign the pending sign-in in once its account, read afresh, owes nothing more, keep it at enrollment for what it still owes, or drop it when it now owes the challenge.
     *
     * @return Passed<Demand>|null
     */
    protected function afterEnrollment(): ?Passed
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

        return $this->signIn($pending->account, $pending->rememberMe);
    }

    /**
     * Sign the account in, noting whether its browser was a known device of it.
     *
     * @return Passed<Demand>|null
     */
    protected function signIn(Model&KeystoneUser $account, RememberMe $rememberMe): ?Passed
    {
        try {
            $knownDevice = $this->guard->signIn($account, $rememberMe);
        } catch (Barred) {
            return null;
        }

        return new Passed(Demand::SIGN_IN, SecurityEventType::SIGNED_IN, knownDevice: $knownDevice);
    }

    /**
     * Hold the pending sign-in on at enrollment, its second factor passed.
     *
     * @return Passed<Demand>|null
     */
    protected function holdAtEnrollment(): ?Passed
    {
        if (! $this->passSecondFactor()) {
            return null;
        }

        return new Passed(Demand::ENROLLMENT, SecurityEventType::SIGN_IN_HELD, reason: 'keystone.enrollment');
    }

    /**
     * Note that the pending sign-in passed its second factor, unless its account is barred.
     */
    protected function passSecondFactor(): bool
    {
        try {
            $this->guard->passSecondFactor();
        } catch (Barred) {
            return false;
        }

        return true;
    }
}

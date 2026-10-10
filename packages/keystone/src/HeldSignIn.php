<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
readonly class HeldSignIn
{
    /**
     * Create a new held sign-in instance.
     */
    public function __construct(
        public int|string $accountId,
        public string $firstFactor,
        public PendingOrigin $origin,
        public PendingStage $stage,
        public string $intendedUrl,
        public CarbonImmutable $heldAt,
        public int $epoch,
        public bool $secondFactorPassed,
        public ?int $pendingChallengeId,
        public RememberMe $rememberMe,
    ) {
        //
    }

    /**
     * Get the time it ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->heldAt->addSeconds(PendingSignIn::LIFETIME_SECONDS);
    }

    /**
     * Get the sign-in as it is once it passed its second factor, held on at enrollment for what it still owes.
     */
    public function passSecondFactor(): self
    {
        return new self(
            accountId: $this->accountId,
            firstFactor: $this->firstFactor,
            origin: $this->origin,
            stage: PendingStage::ENROLLMENT,
            intendedUrl: $this->intendedUrl,
            heldAt: $this->heldAt,
            epoch: $this->epoch,
            secondFactorPassed: true,
            pendingChallengeId: $this->pendingChallengeId,
            rememberMe: $this->rememberMe,
        );
    }

    /**
     * Convert it to the pending sign-in of the account it holds.
     */
    public function toPendingSignIn(Model&KeystoneUser $account): PendingSignIn
    {
        return new PendingSignIn(
            account: $account,
            firstFactor: $this->firstFactor,
            origin: $this->origin,
            stage: $this->stage,
            intendedUrl: $this->intendedUrl,
            heldAt: $this->heldAt,
            epoch: $this->epoch,
            secondFactorPassed: $this->secondFactorPassed,
            pendingChallengeId: $this->pendingChallengeId,
            rememberMe: $this->rememberMe,
        );
    }
}

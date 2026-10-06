<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
readonly class PendingSignIn
{
    /**
     * How long a pending sign-in lasts from its hold.
     */
    public const int LIFETIME_SECONDS = 900;

    /**
     * Create a new pending sign-in instance.
     *
     * @param  int|null  $pendingChallenge  the id of the pending challenge the hold opened, when its browser wasn't a known device
     */
    public function __construct(
        public Model&KeystoneUser $account,
        public string $firstFactor,
        public PendingOrigin $origin,
        public PendingStage $stage,
        public string $intendedUrl,
        public CarbonImmutable $heldAt,
        public int $epoch,
        public bool $secondFactorPassed,
        public ?int $pendingChallenge = null,
    ) {
        //
    }
}

<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @internal
 */
readonly class SudoInProgress
{
    /**
     * How long a sudo-in-progress lasts from the refusal that started it.
     */
    public const int LIFETIME_SECONDS = 900;

    /**
     * Create a new sudo-in-progress instance.
     *
     * @param  string|null  $firstFactor  the type that passed the first step, or null while that step is still owed
     */
    public function __construct(
        public string $intendedUrl,
        public CarbonImmutable $startedAt,
        public ?string $firstFactor,
    ) {
        //
    }

    /**
     * Get the time it ends.
     */
    public function endsAt(): CarbonImmutable
    {
        return $this->startedAt->addSeconds(self::LIFETIME_SECONDS);
    }

    /**
     * Get the surface the step it is at verifies on: sign-in until a first factor passed, the challenge after.
     */
    public function surface(): Surface
    {
        return $this->firstFactor === null ? Surface::SIGN_IN : Surface::CHALLENGE;
    }
}

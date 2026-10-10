<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class Registering
{
    /**
     * How long an address waits for its registration to finish.
     */
    public const int WINDOW_SECONDS = 1800;

    /**
     * The time the registration's window ends.
     */
    public CarbonImmutable $endsAt;

    /**
     * Create a new registering instance.
     */
    public function __construct(
        public string $address,
        public CarbonImmutable $startedAt,
        public bool $verified,
    ) {
        $this->endsAt = $startedAt->addSeconds(self::WINDOW_SECONDS);
    }
}

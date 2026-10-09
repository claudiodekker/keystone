<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class Registering
{
    /**
     * How long a proven address waits for its registration to finish.
     */
    public const int WINDOW_SECONDS = 1800;

    /**
     * Create a new registering instance.
     */
    public function __construct(
        public string $address,
        public CarbonImmutable $endsAt,
    ) {
        //
    }
}

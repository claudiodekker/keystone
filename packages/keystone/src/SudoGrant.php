<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class SudoGrant
{
    /**
     * Create a new sudo grant instance.
     */
    public function __construct(
        public CarbonImmutable $endsAt,
        public Subnet $subnet,
    ) {
        //
    }
}

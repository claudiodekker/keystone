<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class IssuedLink
{
    /**
     * Create a new issued link instance.
     */
    public function __construct(
        #[\SensitiveParameter] public string $url,
        public CarbonImmutable $expiresAt,
    ) {
        //
    }
}

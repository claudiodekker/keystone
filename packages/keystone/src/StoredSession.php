<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class StoredSession
{
    /**
     * Create a new stored session instance.
     */
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonImmutable $lastActiveAt,
    ) {
        //
    }
}

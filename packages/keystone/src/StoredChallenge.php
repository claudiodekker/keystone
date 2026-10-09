<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class StoredChallenge
{
    /**
     * Create a new stored challenge instance.
     */
    public function __construct(
        public int $id,
        public int|string $accountId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonImmutable $heldAt,
    ) {
        //
    }
}

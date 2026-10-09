<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonInterface;

/**
 * @internal
 */
readonly class ListedSession
{
    /**
     * Create a new listed session instance.
     */
    public function __construct(
        public string $handle,
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonInterface $lastActiveAt,
        public bool $current,
        public ?int $rememberTokenId,
    ) {
        //
    }
}

<?php

namespace ClaudioDekker\Keystone;

use RuntimeException;

/**
 * @internal
 */
class Throttled extends RuntimeException
{
    /**
     * Create a new throttled exception instance.
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('A rate limit is spent.');
    }
}

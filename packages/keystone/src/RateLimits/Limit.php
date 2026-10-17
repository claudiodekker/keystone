<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * One of the rate limiter's limits, each counted over its own fixed window.
 *
 * @internal
 */
enum Limit: string
{
    case FAILED_ATTEMPT = 'failed_attempt_limit';
    case REQUEST = 'request_limit';

    /**
     * Get the seconds a count of the limit lives from its first take.
     */
    public function windowSeconds(): int
    {
        return match ($this) {
            Limit::FAILED_ATTEMPT => 60 * 60,
            Limit::REQUEST => 60,
        };
    }
}

<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
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

    /**
     * Get the reason a trip of the limit is recorded with.
     */
    public function reason(): string
    {
        return 'keystone.'.$this->value;
    }
}

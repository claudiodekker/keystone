<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * @internal
 */
enum Limit: string
{
    case DAILY_FAILED_ATTEMPT = 'daily_failed_attempt_limit';
    case FAILED_ATTEMPT = 'failed_attempt_limit';
    case REQUEST = 'request_limit';

    /**
     * Get the seconds a count of the limit lives from its first take.
     */
    public function windowSeconds(): int
    {
        return match ($this) {
            Limit::DAILY_FAILED_ATTEMPT => 60 * 60 * 24,
            Limit::FAILED_ATTEMPT => 60 * 60,
            Limit::REQUEST => 60,
        };
    }

    /**
     * Determine if a trip of the limit alerts the account's owner, which only a failed-attempt limit does, since a request limit names no credential.
     */
    public function alertsOwner(): bool
    {
        return match ($this) {
            Limit::DAILY_FAILED_ATTEMPT, Limit::FAILED_ATTEMPT => true,
            Limit::REQUEST => false,
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

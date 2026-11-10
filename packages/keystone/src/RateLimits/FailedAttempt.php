<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * @internal
 */
readonly class FailedAttempt
{
    /**
     * Create the failed attempt from every count it took.
     *
     * @param  list<CountedAttempt>  $counts
     */
    public function __construct(public array $counts)
    {
        //
    }
}

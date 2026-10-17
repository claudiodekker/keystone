<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * An attempt the limiter counted: the count its key reached, and when the window it was counted in ends.
 *
 * @internal
 */
readonly class CountedAttempt
{
    /**
     * Create the counted attempt.
     */
    public function __construct(
        public string $key,
        public Limit $limit,
        public int $count,
        public int $availableAt,
    ) {
        //
    }

    /**
     * Determine if the count is over the allowance.
     */
    public function isOver(int $allowance): bool
    {
        return $this->count > $allowance;
    }
}

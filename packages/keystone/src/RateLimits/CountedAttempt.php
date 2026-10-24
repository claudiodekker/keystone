<?php

namespace ClaudioDekker\Keystone\RateLimits;

/**
 * @internal
 */
readonly class CountedAttempt
{
    /**
     * Create the counted attempt.
     */
    public function __construct(
        public string $key,
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

    /**
     * Determine if the count is the first over the allowance, which trips the limit.
     */
    public function isFirstOver(int $allowance): bool
    {
        return $this->count === $allowance + 1;
    }

    /**
     * Determine if the window the attempt was counted in ends at the timestamp.
     */
    public function isInWindowEndingAt(int $timestamp): bool
    {
        return $this->availableAt === $timestamp;
    }
}

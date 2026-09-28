<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
readonly class TakenAttempt
{
    /**
     * Create a new taken attempt instance.
     */
    public function __construct(
        public string $key,
        public ?int $windowEndsAt,
    ) {
        //
    }
}

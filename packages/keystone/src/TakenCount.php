<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
readonly class TakenCount
{
    /**
     * Create a new taken count instance.
     */
    public function __construct(
        public string $key,
        public int $windowSeconds,
        public ?int $windowEndsAt,
    ) {
        //
    }
}

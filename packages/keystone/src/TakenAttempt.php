<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
readonly class TakenAttempt
{
    /**
     * Create a new taken attempt instance.
     *
     * @param  non-empty-list<TakenCount>  $counts
     */
    public function __construct(
        public array $counts,
    ) {
        //
    }
}

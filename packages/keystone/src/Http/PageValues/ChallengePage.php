<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class ChallengePage
{
    /**
     * Create a new challenge page value.
     *
     * @param  list<array{type: string, shape: string}>  $types
     */
    public function __construct(
        public array $types,
        public string $preselect,
    ) {
        //
    }
}

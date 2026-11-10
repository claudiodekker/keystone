<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * @api
 */
readonly class ChallengePage
{
    /**
     * Create the page value.
     *
     * @param  array<int, array{name: string, shape: string}>  $types  the second-factor types the account can answer with
     * @param  string|null  $preselected  the name of the type the page opens on
     */
    public function __construct(
        public array $types,
        public ?string $preselected,
    ) {
        //
    }
}

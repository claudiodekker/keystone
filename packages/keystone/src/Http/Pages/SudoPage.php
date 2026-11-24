<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * @api
 */
readonly class SudoPage
{
    /**
     * Create the page value.
     *
     * @param  array<int, array{name: string, shape: string}>  $types  the types the account can confirm it is them with at this step
     * @param  string|null  $preselected  the name of the type the page opens on
     */
    public function __construct(
        public array $types,
        public ?string $preselected,
    ) {
        //
    }
}

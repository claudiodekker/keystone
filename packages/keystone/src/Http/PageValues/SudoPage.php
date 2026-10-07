<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class SudoPage
{
    /**
     * Create a new sudo page value.
     *
     * @param  list<array{type: string, shape: string}>  $types  empty when the account holds nothing it can answer with
     * @param  string  $surface  the surface each type's partial renders for, sign-in or challenge
     */
    public function __construct(
        public array $types,
        public ?string $preselect,
        public string $surface,
    ) {
        //
    }
}

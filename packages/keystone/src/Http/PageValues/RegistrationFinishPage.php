<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class RegistrationFinishPage
{
    /**
     * Create a new registration finish page instance.
     *
     * @param  string  $address  the address the spent link proved
     * @param  list<array{type: string, shape: string}>  $types
     */
    public function __construct(
        public string $address,
        public array $types,
        public ?string $status,
    ) {
        //
    }
}

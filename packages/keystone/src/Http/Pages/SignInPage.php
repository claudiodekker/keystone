<?php

namespace ClaudioDekker\Keystone\Http\Pages;

/**
 * @api
 */
readonly class SignInPage
{
    /**
     * Create the page value.
     *
     * @param  array<int, array{name: string, shape: string}>  $types
     */
    public function __construct(
        public array $types,
        public ?string $status,
    ) {
        //
    }
}

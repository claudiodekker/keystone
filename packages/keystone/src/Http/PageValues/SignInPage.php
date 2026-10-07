<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class SignInPage
{
    /**
     * Create a new sign-in page value.
     *
     * @param  list<array{type: string, shape: string}>  $types
     */
    public function __construct(
        public array $types,
        public ?string $status,
        public bool $rememberOffered = false,
    ) {
        //
    }
}

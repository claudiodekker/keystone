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
     * @param  bool  $registrationOpen  whether a listed credential type serves registration, so the page may offer to create an account
     */
    public function __construct(
        public array $types,
        public ?string $status,
        public bool $rememberOffered = false,
        public bool $registrationOpen = false,
    ) {
        //
    }
}

<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class RegisterPage
{
    /**
     * Create a new register page instance.
     */
    public function __construct(
        public ?string $status,
        public bool $mailsLink,
    ) {
        //
    }
}

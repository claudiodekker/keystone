<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class Verification
{
    /**
     * Create the verification.
     *
     * With no subject the credentials are empty, which a type can't tell from a subject who holds none.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, Credential>  $credentials
     */
    public function __construct(
        public Surface $surface,
        public array $input,
        public array $credentials,
    ) {
        //
    }
}

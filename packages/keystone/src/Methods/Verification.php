<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * What a type verifies: the surface, its own submitted fields and the subject's credentials of the type.
 *
 * It carries no user. With no subject the credentials are empty, which a type can't tell from a subject who holds none.
 *
 * @internal
 */
readonly class Verification
{
    /**
     * Create the verification.
     *
     * @param  array<string, mixed>  $input
     * @param  list<Credential>  $credentials
     */
    public function __construct(
        public Surface $surface,
        public array $input,
        public array $credentials,
    ) {
        //
    }
}

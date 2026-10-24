<?php

namespace ClaudioDekker\Keystone\Methods;

use SensitiveParameter;

/**
 * @internal
 */
readonly class Credential
{
    /**
     * Create the credential.
     */
    public function __construct(
        public int|string $id,
        #[SensitiveParameter] public ?string $secret,
    ) {
        //
    }
}

<?php

namespace ClaudioDekker\Keystone\Methods;

use SensitiveParameter;

/**
 * A stored credential as a type sees it: its id and its decrypted secret, with no owner.
 *
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

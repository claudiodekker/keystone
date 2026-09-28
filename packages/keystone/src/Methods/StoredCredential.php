<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class StoredCredential
{
    /**
     * Create a new stored credential instance.
     */
    public function __construct(
        public int $id,
        public ?string $identifier,
        #[\SensitiveParameter] public ?string $secret,
        public ?string $label,
    ) {
        //
    }
}

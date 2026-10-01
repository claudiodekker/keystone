<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class EnrolledCredential
{
    /**
     * Create a new enrolled credential instance.
     */
    public function __construct(
        public ?string $identifier,
        #[\SensitiveParameter] public ?string $secret,
        public ?string $label = null,
    ) {
        //
    }
}

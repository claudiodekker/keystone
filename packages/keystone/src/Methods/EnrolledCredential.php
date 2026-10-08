<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class EnrolledCredential
{
    /**
     * Create a new enrolled credential instance.
     *
     * @param  bool  $replacesExisting  whether the credential takes the place of every credential of its type the account holds
     */
    public function __construct(
        public ?string $identifier,
        #[\SensitiveParameter] public ?string $secret,
        public ?string $label = null,
        public bool $replacesExisting = false,
    ) {
        //
    }
}

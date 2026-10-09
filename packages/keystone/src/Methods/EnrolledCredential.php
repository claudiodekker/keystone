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

    /**
     * Create an enrolled credential that takes the place of every credential of its type the account holds.
     */
    public static function replacing(?string $identifier, #[\SensitiveParameter] ?string $secret, ?string $label = null): self
    {
        return new self($identifier, $secret, $label, replacesExisting: true);
    }
}

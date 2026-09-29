<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Proof
{
    /**
     * Create a new proof instance.
     */
    protected function __construct(
        public bool $proven,
        public ?int $credentialId,
        public ?string $reason,
        #[\SensitiveParameter] public ?string $updatedSecret = null,
    ) {
        //
    }

    /**
     * Prove the stored credential, optionally with the secret core should store in place of the one verified.
     */
    public static function proven(StoredCredential $credential, #[\SensitiveParameter] ?string $updatedSecret = null): static
    {
        return new static(proven: true, credentialId: $credential->id, reason: null, updatedSecret: $updatedSecret);
    }

    /**
     * Reject the input, optionally naming the credential it was checked against.
     */
    public static function rejected(string $reason, ?StoredCredential $credential = null): static
    {
        return new static(proven: false, credentialId: $credential?->id, reason: $reason);
    }
}

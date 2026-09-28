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
    ) {
        //
    }

    /**
     * Prove the stored credential.
     */
    public static function proven(StoredCredential $credential): static
    {
        return new static(proven: true, credentialId: $credential->id, reason: null);
    }

    /**
     * Reject the input, optionally naming the credential it was checked against.
     */
    public static function rejected(string $reason, ?StoredCredential $credential = null): static
    {
        return new static(proven: false, credentialId: $credential?->id, reason: $reason);
    }
}

<?php

namespace ClaudioDekker\Keystone\Methods;

use Closure;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Proof
{
    /**
     * Create a new proof instance.
     *
     * @param  (Closure(): string)|null  $updatedSecret
     */
    protected function __construct(
        public bool $proven,
        public ?int $credentialId,
        public ?string $reason,
        public ?Closure $updatedSecret = null,
        #[\SensitiveParameter] public ?string $advancedSecret = null,
    ) {
        //
    }

    /**
     * Prove the stored credential, optionally making the secret core stores in place of the one verified once it signs in.
     *
     * @param  (Closure(): string)|null  $updatedSecret
     */
    public static function proven(StoredCredential $credential, ?Closure $updatedSecret = null): static
    {
        return new static(proven: true, credentialId: $credential->id, reason: null, updatedSecret: $updatedSecret);
    }

    /**
     * Prove the stored credential by moving its secret on, which core stores before the proof counts, refusing the proof when another moved it first.
     */
    public static function advanced(StoredCredential $credential, #[\SensitiveParameter] string $secret): static
    {
        return new static(proven: true, credentialId: $credential->id, reason: null, advancedSecret: $secret);
    }

    /**
     * Reject the input, optionally naming the credential it was checked against.
     */
    public static function rejected(string $reason, ?StoredCredential $credential = null): static
    {
        return new static(proven: false, credentialId: $credential?->id, reason: $reason);
    }
}

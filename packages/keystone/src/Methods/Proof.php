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
        public ?EnrolledCredential $enrolled = null,
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
     * Prove the credential by moving its secret on to the next one.
     */
    public static function advanced(StoredCredential $credential, #[\SensitiveParameter] string $secret): static
    {
        return new static(proven: true, credentialId: $credential->id, reason: null, advancedSecret: $secret);
    }

    /**
     * Prove a new credential for core to store, as an enrollment does.
     */
    public static function enrolled(EnrolledCredential $credential): static
    {
        return new static(proven: true, credentialId: null, reason: null, enrolled: $credential);
    }

    /**
     * Reject the input, optionally naming the credential it was checked against.
     */
    public static function rejected(string $reason, ?StoredCredential $credential = null): static
    {
        return new static(proven: false, credentialId: $credential?->id, reason: $reason);
    }
}

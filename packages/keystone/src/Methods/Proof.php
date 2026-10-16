<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * What a type's verify proved: one of the credentials it was given, or nothing. It names a credential, never a user.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Proof
{
    /**
     * Create the proof.
     */
    protected function __construct(public int|string|null $credential)
    {
        //
    }

    /**
     * Create a proof of one of the credentials the type was given.
     */
    public static function proven(Credential $credential): static
    {
        return new static($credential->id);
    }

    /**
     * Create a rejection.
     */
    public static function rejected(): static
    {
        return new static(null);
    }

    /**
     * Determine if the proof proves a credential.
     */
    public function isProven(): bool
    {
        return $this->credential !== null;
    }
}

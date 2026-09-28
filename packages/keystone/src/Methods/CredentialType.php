<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
interface CredentialType
{
    /**
     * Get the type's name, unique among registered types.
     */
    public function name(): string;

    /**
     * Get the initiate shape of each surface the type serves.
     *
     * @return array<value-of<Surface>, InitiateShape>
     */
    public function surfaces(): array;

    /**
     * Determine if a proof of this type represents multiple factors, so it signs in without a challenge.
     */
    public function representsMultipleFactors(): bool;

    /**
     * Get the validation rules for the input verify takes on the surface.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array;

    /**
     * Verify the input against the subject's credentials of this type.
     *
     * With no subject the list is empty, and the type does the same work as for a real one.
     *
     * @param  array<string, mixed>  $input
     * @param  list<StoredCredential>  $credentials
     */
    public function verify(Surface $surface, array $input, array $credentials): Proof;
}

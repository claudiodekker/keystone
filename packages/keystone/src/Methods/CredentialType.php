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
     * Determine if the type's failures share one count across the flows behind a first factor.
     */
    public function sharesFailedAttempts(): bool;

    /**
     * Get what is wrong with the type's configuration, for boot to refuse.
     *
     * @return list<string>
     */
    public function configFailures(): array;

    /**
     * Get the validation rules for the input verify takes on the surface.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array;

    /**
     * Start the type's ceremony on the surface for the named account, returning what core keeps in the ceremony slot and what the form shows.
     */
    public function initiate(Surface $surface, string $accountName): Initiation;

    /**
     * Verify the input against the subject's credentials of this type, and the ceremony initiate started, if any.
     *
     * With no subject the list is empty, and the type does the same work as for a real one.
     *
     * @param  array<string, mixed>  $input
     * @param  list<StoredCredential>  $credentials
     */
    public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof;
}

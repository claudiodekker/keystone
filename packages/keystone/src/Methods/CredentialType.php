<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * A way to prove identity that a method package registers, which reaches core only through this contract.
 *
 * @internal
 */
interface CredentialType
{
    /**
     * Get the type's name, unique among registered types.
     */
    public function name(): string;

    /**
     * Get how a page starts the type on the surface, or null when the type doesn't serve it.
     */
    public function initiateShape(Surface $surface): ?InitiateShape;

    /**
     * Determine if one proof of the type proves two factors on its own.
     */
    public function isMultiFactorOnItsOwn(): bool;

    /**
     * Get the rules for the type's own fields on the surface; core passes on only the fields they name.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array;

    /**
     * Verify the submitted fields against the subject's credentials, doing the same work when there are none.
     */
    public function verify(Verification $verification): Proof;
}

<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;

class FakeSurfacesType implements CredentialType
{
    /**
     * Create the type.
     *
     * @param  list<Surface>  $surfaces
     */
    public function __construct(
        protected string $name,
        protected array $surfaces,
    ) {
        //
    }

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the form shape on the surfaces the type serves, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if (! in_array($surface, $this->surfaces, true)) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which it doesn't.
     */
    public function isMultiFactor(): bool
    {
        return false;
    }

    /**
     * Get no rules, since nothing is submitted to it.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [];
    }

    /**
     * Reject every verification.
     */
    public function verify(Verification $verification): Proof
    {
        return Proof::rejected();
    }
}

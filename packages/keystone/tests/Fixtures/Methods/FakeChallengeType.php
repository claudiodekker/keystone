<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;

class FakeChallengeType implements CredentialType
{
    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'challenge-only';
    }

    /**
     * Get the form shape on a challenge, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::CHALLENGE) {
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

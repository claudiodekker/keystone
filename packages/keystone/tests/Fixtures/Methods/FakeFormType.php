<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Models\UserCredential;

/**
 * A credential type of the form shape that compares a stored secret, and deliberately not a password.
 *
 * It is its own test support.
 */
class FakeFormType implements CredentialType, CredentialTypeSupport
{
    /**
     * The secret every arranged credential holds.
     */
    protected const string SECRET = 'correct horse';

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'form';
    }

    /**
     * Get the form shape on sign-in, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which it doesn't.
     */
    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    /**
     * Get the rules for the secret.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [];
    }

    /**
     * Prove the credential whose secret matches.
     */
    public function verify(Verification $verification): Proof
    {
        foreach ($verification->credentials as $credential) {
            if (hash_equals((string) $credential->secret, (string) $verification->input['secret'])) {
                return Proof::proven($credential);
            }
        }

        return Proof::rejected();
    }

    /**
     * Get the type this supports, which is itself.
     */
    public function type(): CredentialType
    {
        return $this;
    }

    /**
     * Give the user a credential holding the fixture secret.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): void
    {
        UserCredential::factory()->for($user, 'user')->create([
            'type' => $this->name(),
            'secret' => static::SECRET,
        ]);
    }

    /**
     * Get the fields that prove the arranged credential.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array
    {
        return ['secret' => static::SECRET];
    }

    /**
     * Get a secret no credential holds.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array
    {
        return ['secret' => 'wrong horse'];
    }
}

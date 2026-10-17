<?php

namespace ClaudioDekker\Keystone\Password\AppTests;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Assert;

/**
 * Let AppTests drive the password type: arrange a password the way the app hashes it, and prove or reject it.
 *
 * @api
 */
class PasswordSupport implements CredentialTypeSupport
{
    /**
     * The password every arranged credential holds.
     */
    public const string PASSWORD = 'correct horse battery staple';

    /**
     * Get the installed password type, naming the fix when it isn't registered.
     */
    public function type(): CredentialType
    {
        return Keystone::credentialTypes()->find('password', Surface::SIGN_IN)
            ?? Assert::fail('The password type is not registered. Install claudiodekker/keystone-password.');
    }

    /**
     * Give the user the fixture password, hashed with the app's driver.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): static
    {
        UserCredential::factory()->for($user, 'user')->create([
            'type' => 'password',
            'secret' => Hash::make(static::PASSWORD),
        ]);

        return $this;
    }

    /**
     * Get the fields that prove the fixture password.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array
    {
        return ['password' => static::PASSWORD];
    }

    /**
     * Get a password no arranged credential holds.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array
    {
        return ['password' => 'wrong horse battery staple'];
    }
}

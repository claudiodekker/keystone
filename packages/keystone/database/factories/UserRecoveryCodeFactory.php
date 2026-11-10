<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRecoveryCode>
 */
class UserRecoveryCodeFactory extends Factory
{
    /**
     * Get the default state: the digest of a code nobody was shown, held by a new user from the app's factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Keystone::userModel()::factory(),
            'digest' => RecoveryCode::generate()->digest(),
        ];
    }

    /**
     * Store the digest of the given code, as the user would type it.
     */
    public function code(string $code): static
    {
        return $this->state([
            'digest' => RecoveryCode::fromInput($code)->digest(),
        ]);
    }
}

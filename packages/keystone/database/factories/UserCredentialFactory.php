<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Make credentials for the app's users.
 *
 * @extends Factory<UserCredential>
 */
class UserCredentialFactory extends Factory
{
    /**
     * Get the default state: a credential of a type nobody registered, so it never answers by accident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Keystone::userModel()::factory(),
            'type' => 'unregistered',
            'secret' => null,
        ];
    }
}

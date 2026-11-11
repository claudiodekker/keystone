<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPendingChallenge>
 */
class UserPendingChallengeFactory extends Factory
{
    /**
     * Get the default state of a challenge held just now from a browser the account doesn't know.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Keystone::userModel()::factory(),
        ];
    }
}

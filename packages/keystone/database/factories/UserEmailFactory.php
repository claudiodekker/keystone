<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEmail>
 */
class UserEmailFactory extends Factory
{
    /**
     * Get the default state: an unverified address of a new user from the app's factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Keystone::userModel()::factory(),
            'address' => fake()->unique()->safeEmail(),
        ];
    }

    /**
     * Mark the address verified.
     */
    public function verified(): static
    {
        return $this->state([
            'verified_address' => fn (array $attributes) => $attributes['address'],
        ]);
    }
}

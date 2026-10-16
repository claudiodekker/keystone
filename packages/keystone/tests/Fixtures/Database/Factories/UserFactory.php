<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Database\Factories;

use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Make fixture users, as an app's own user factory does.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Get the default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}

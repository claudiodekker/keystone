<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Database\Factories;

use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password every user made in this process shares, hashed once as the skeleton's factory does.
     */
    protected static ?string $password;

    /**
     * Get the default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }
}

<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\Session\RememberCookie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<UserRememberToken>
 */
class UserRememberTokenFactory extends Factory
{
    /**
     * Get the default state of a token issued just now, on the account's first credential epoch, for 30 days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Keystone::userModel()::factory(),
            'digest' => RememberCookie::generate()->digest(),
            'epoch' => 0,
            'expires_at' => Date::now()->addDays(30),
        ];
    }

    /**
     * Store the digest of the given cookie, as a browser holding it asked to be remembered.
     */
    public function cookie(RememberCookie $cookie): static
    {
        return $this->state([
            'digest' => $cookie->digest(),
        ]);
    }
}

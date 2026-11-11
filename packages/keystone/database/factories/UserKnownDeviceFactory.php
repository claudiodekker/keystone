<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Devices\DeviceCookie;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<UserKnownDevice>
 */
class UserKnownDeviceFactory extends Factory
{
    /**
     * Get the default state: a device seen just now through a cookie no browser holds, with no labels, known to a new user from the app's factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cookie = DeviceCookie::generate();

        return [
            'user_id' => Keystone::userModel()::factory(),
            'digest' => $cookie->digest(),
            'device_digest' => $cookie->deviceDigest(),
            'last_seen_at' => Date::now(),
        ];
    }

    /**
     * Store the digests of the given cookie, as a browser holding it signed in.
     */
    public function cookie(DeviceCookie $cookie): static
    {
        return $this->state([
            'digest' => $cookie->digest(),
            'device_digest' => $cookie->deviceDigest(),
        ]);
    }
}

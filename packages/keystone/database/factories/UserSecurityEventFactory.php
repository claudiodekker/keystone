<?php

namespace ClaudioDekker\Keystone\Database\Factories;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSecurityEvent>
 */
class UserSecurityEventFactory extends Factory
{
    /**
     * Get the default state: a sign-out by a new user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'type' => SecurityEventType::SIGNED_OUT,
            'user_id' => Keystone::userModel()::factory(),
            'actor' => Actor::USER,
        ];
    }
}

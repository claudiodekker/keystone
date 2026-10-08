<?php

use ClaudioDekker\Keystone\KnownDevices;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

it('never forgets the devices of an account for a model without a key', function () {
    $user = User::factory()->create();
    DB::table('user_known_devices')->insert([
        'user_id' => $user->getKey(),
        'device_hash' => hash('sha256', 'device'),
        'cookie_hash' => hash('sha256', 'device.secret'),
        'last_seen_at' => now(),
        'created_at' => now(),
    ]);

    expect(fn () => (new KnownDevices(new User))->forget())->toThrow(LogicException::class);

    $this->assertDatabaseCount('user_known_devices', 1);
});

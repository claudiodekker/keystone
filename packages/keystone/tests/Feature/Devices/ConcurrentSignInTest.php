<?php

// SQL race: two sign-ins sent at once from one new browser, which no AppTest can interleave.

use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

it('signs in the second of two sign-ins a new browser sent at once, keeping one row for the device', function () {
    $user = User::factory()->create();
    $sent = '7d1e4b9a2c6f08e35b1d4a7c9e2f6b80.'.str_repeat('c4', 32);
    $this->app->instance('request', Request::create('/login/form', 'POST', cookies: ['__Host-keystone_device' => $sent]));
    UserKnownDevice::creating(function (UserKnownDevice $device) {
        UserKnownDevice::flushEventListeners();

        UserKnownDevice::factory()->create([
            'user_id' => $device->user_id,
            'device_digest' => $device->device_digest,
        ]);
    });

    app(KnownDevices::class)->signIn($user);

    expect(UserKnownDevice::query()->where('user_id', $user->getKey())->count())->toBe(1)
        ->and(UserKnownDevice::query()->where('user_id', $user->getKey())->value('digest'))->toBe(hash('sha256', app('cookie')->queued('__Host-keystone_device')->getValue()));
});

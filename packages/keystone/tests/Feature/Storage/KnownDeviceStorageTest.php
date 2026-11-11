<?php

// Storage shape: what sits in the known devices table at rest, which no app can see through a page.

use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

it("keeps the SHA-256 digests of the browser's whole cookie and of its device part, never the cookie itself", function () {
    $user = User::factory()->create();
    $sent = '7d1e4b9a2c6f08e35b1d4a7c9e2f6b80.'.str_repeat('c4', 32);
    $this->app->instance('request', Request::create('/login/form', 'POST', cookies: ['__Host-keystone_device' => $sent]));

    app(KnownDevices::class)->signIn($user);

    $handed = app('cookie')->queued('__Host-keystone_device')->getValue();
    $stored = UserKnownDevice::query()->toBase()->where('user_id', $user->getKey())->sole();

    expect($handed)->toStartWith('7d1e4b9a2c6f08e35b1d4a7c9e2f6b80.')
        ->and($stored->device_digest)->toBe('a2deabba6408fdd70a1131b2eb7c20cb9ad4dbc3afb4eaf0949b93dc7cf47a4d')
        ->and($stored->digest)->toBe(hash('sha256', $handed))
        ->and(json_encode($stored))->not->toContain('7d1e4b9a2c6f08e35b1d4a7c9e2f6b80');
});

it('keeps the IP address and the user agent encrypted at rest', function (string $column, string $value) {
    $device = UserKnownDevice::factory()->create([$column => $value]);

    $stored = UserKnownDevice::query()->toBase()->where('id', $device->getKey())->value($column);

    expect($stored)->not->toBe($value)
        ->and(Crypt::decryptString($stored))->toBe($value);
})->with([
    'the IP address' => ['ip_address', '203.0.113.9'],
    'the user agent' => ['user_agent', 'Mozilla/5.0 (X11; Linux x86_64)'],
]);

it('leaves the digests, the IP address and the user agent out of the array form', function () {
    $device = UserKnownDevice::factory()->create([
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
    ]);

    expect(array_keys($device->toArray()))->not->toContain('device_digest', 'digest', 'ip_address', 'user_agent');
});

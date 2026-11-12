<?php

// Subnet binding through the fixture app's trusted proxy: each app owns its trusted proxies, so no AppTest can set them.

use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['keystone.require_recovery_codes' => false, 'keystone.require_second_factor' => false]);
    TrustProxies::at('10.0.0.1');

    Route::middleware(['web', 'auth', 'sudo'])->get('/gated', fn () => response()->noContent());

    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
});

afterEach(function () {
    TrustProxies::flushState();
});

it('binds the grant to the network of the address the trusted proxy forwarded', function (string $signedInFrom, string $sameNetwork, string $otherNetwork) {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', $signedInFrom)
        ->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $same = $this->withHeader('X-Forwarded-For', $sameNetwork)->getJson('/gated');

    Auth::forgetGuards();
    $other = $this->withHeader('X-Forwarded-For', $otherNetwork)->getJson('/gated');

    expect($same->getStatusCode())->toBe(204)
        ->and($other->getStatusCode())->toBe(403);
})->with([
    'an IPv4 /24' => ['203.0.113.9', '203.0.113.200', '203.0.114.9'],
    'an IPv6 /64' => ['2001:db8:1:2::9', '2001:db8:1:2:ffff::1', '2001:db8:1:3::9'],
    'an IPv4-mapped IPv6 address, as its IPv4 /24' => ['::ffff:203.0.113.9', '203.0.113.77', '::ffff:203.0.114.9'],
]);

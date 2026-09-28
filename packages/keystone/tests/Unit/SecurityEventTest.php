<?php

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function storeEvent(): SecurityEvent
{
    return SecurityEvent::create([
        'occurred_at' => now(),
        'type' => SecurityEventType::SIGNED_IN,
        'actor' => Actor::USER,
        'ip_address' => '203.0.113.7',
        'location' => 'Amsterdam, NL',
        'user_agent' => 'Mozilla/5.0 Firefox',
    ]);
}

it('encrypts the IP address, location and user agent at rest', function () {
    storeEvent();

    $row = DB::table('user_security_events')->sole();

    expect(Crypt::decryptString($row->ip_address))->toBe('203.0.113.7')
        ->and(Crypt::decryptString($row->location))->toBe('Amsterdam, NL')
        ->and(Crypt::decryptString($row->user_agent))->toBe('Mozilla/5.0 Firefox')
        ->and([$row->ip_address, $row->location, $row->user_agent])->not->toContain('203.0.113.7', 'Amsterdam, NL', 'Mozilla/5.0 Firefox');
});

it('hides the IP address, location and user agent from serialization', function () {
    $serialized = storeEvent()->toArray();

    expect($serialized)->not->toHaveKeys(['ip_address', 'location', 'user_agent'])
        ->and($serialized)->toHaveKeys(['type', 'actor', 'occurred_at']);
});

it('keeps an account\'s events when it is hard deleted, belonging to nobody', function () {
    $user = User::factory()->create();
    storeEvent()->forceFill(['user_id' => $user->getKey()])->save();

    $user->forceDelete();

    expect(SecurityEvent::sole()->user_id)->toBeNull();
});

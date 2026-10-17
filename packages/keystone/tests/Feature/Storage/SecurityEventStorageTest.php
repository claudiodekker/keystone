<?php

// Storage shape: what sits in the security events table at rest, which no app can see through a page.

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Crypt;

it('keeps the IP address, the location and the user agent encrypted at rest', function (string $column, string $value) {
    $event = UserSecurityEvent::factory()->create([$column => $value]);

    $stored = UserSecurityEvent::query()->toBase()->where('id', $event->getKey())->value($column);

    expect($stored)->not->toBe($value)
        ->and(Crypt::decryptString($stored))->toBe($value);
})->with([
    'the IP address' => ['ip_address', '203.0.113.9'],
    'the location' => ['location', 'Amsterdam, NL'],
    'the user agent' => ['user_agent', 'Mozilla/5.0 (X11; Linux x86_64)'],
]);

it('leaves the IP address, the location and the user agent out of the array form, and puts them in clear in the log context', function () {
    $event = UserSecurityEvent::factory()->create([
        'ip_address' => '203.0.113.9',
        'location' => 'Amsterdam, NL',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
    ]);

    expect(array_keys($event->toArray()))->not->toContain('ip_address', 'location', 'user_agent')
        ->and($event->toLogContext())->toMatchArray([
            'ip_address' => '203.0.113.9',
            'location' => 'Amsterdam, NL',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)',
        ]);
});

it("keeps a removed account's rows under its user id", function () {
    $user = User::factory()->create();
    $event = UserSecurityEvent::factory()->for($user, 'user')->create();

    $user->delete();

    expect($event->fresh()?->user_id)->toBe($user->getKey());
});

it('writes the row though a model listener cancels it', function () {
    $user = User::factory()->create();
    UserSecurityEvent::creating(fn () => false);

    $this->app->make(Recorder::class)->record(SecurityEventType::SIGNED_OUT, account: $user);

    expect(UserSecurityEvent::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

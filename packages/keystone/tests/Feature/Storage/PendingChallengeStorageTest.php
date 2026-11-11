<?php

// Storage shape: what sits in the pending challenges table at rest, which no app can see through a page.

use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use Illuminate\Support\Facades\Crypt;

it('keeps the IP address and the user agent encrypted at rest', function (string $column, string $value) {
    $challenge = UserPendingChallenge::factory()->create([$column => $value]);

    $stored = UserPendingChallenge::query()->toBase()->where('id', $challenge->getKey())->value($column);

    expect($stored)->not->toBe($value)
        ->and(Crypt::decryptString($stored))->toBe($value);
})->with([
    'the IP address' => ['ip_address', '198.51.100.23'],
    'the user agent' => ['user_agent', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0'],
]);

it('leaves the IP address and the user agent out of the array form', function () {
    $challenge = UserPendingChallenge::factory()->create([
        'ip_address' => '198.51.100.23',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0',
    ]);

    expect(array_keys($challenge->toArray()))->not->toContain('ip_address', 'user_agent');
});

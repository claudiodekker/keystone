<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('keeps the time of the event in UTC in the row whatever timezone the app runs in', function () {
    date_default_timezone_set('Asia/Tokyo');
    config(['app.timezone' => 'Asia/Tokyo']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $stored = UserSecurityEvent::query()->toBase()->where('user_id', $user->getAuthIdentifier())->value('occurred_at');

    expect(CarbonImmutable::parse($stored, 'UTC')->toDateTimeString())->toBe('2026-10-17 12:00:00')
        ->and($this->securityEvents()->for($user)->sole()->occurred_at->toDateTimeString())->toBe('2026-10-17 12:00:00');
});

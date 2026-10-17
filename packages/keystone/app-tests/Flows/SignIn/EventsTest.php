<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('records signed_in for the account when a user signs in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');

    $events = $this->securityEvents()->for($user);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($events[0]->user_id)->toBe($user->getAuthIdentifier());
});

it('stores the time of the event in UTC whatever timezone the app runs in', function () {
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

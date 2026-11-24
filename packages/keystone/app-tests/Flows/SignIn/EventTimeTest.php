<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

afterEach(function () {
    date_default_timezone_set('UTC');
});

it('keeps the time of the event in UTC, in the row and in the log line, whatever timezone the app runs in', function () {
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
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->sole()->occurred_at->toDateTimeString())->toBe('2026-10-17 12:00:00')
        ->and(collect($this->securityEvents()->logged())->firstWhere('type', 'signed_in')['occurred_at'])->toStartWith('2026-10-17T12:00:00')->toEndWith('Z');
});

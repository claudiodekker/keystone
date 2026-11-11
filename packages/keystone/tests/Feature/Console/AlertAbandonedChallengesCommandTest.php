<?php

// Unpublished command: what the abandoned-challenge sweep records and mails, and the schedule core gives it, which no app changes.

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('records a challenge left 8 minutes ago as abandoned at the time of its hold, alerts the owner and deletes its row', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-04 13:37:00', 'UTC'));
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $challenge = UserPendingChallenge::factory()->for($ada, 'user')->create([
        'ip_address' => '198.51.100.23',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0',
        'created_at' => now()->subMinutes(8),
    ]);

    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    $event = UserSecurityEvent::query()->sole();

    Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
    Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ada@example.com');
    $this->assertModelMissing($challenge);
    expect($event->type)->toBe(SecurityEventType::CHALLENGE_ABANDONED)
        ->and($event->user_id)->toBe($ada->getKey())
        ->and($event->actor)->toBe(Actor::SYSTEM)
        ->and($event->occurred_at->toDateTimeString())->toBe('2026-05-04 13:29:00')
        ->and($event->ip_address)->toBe('198.51.100.23')
        ->and($event->user_agent)->toBe('Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0');
});

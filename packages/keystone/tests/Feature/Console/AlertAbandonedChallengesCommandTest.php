<?php

// Unpublished command: what the abandoned-challenge sweep records and mails, and the schedule core gives it, which no app changes.

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\AppTests\IpLocationFake;
use ClaudioDekker\Keystone\AppTests\SessionInfoFake;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Models\UserPendingChallenge;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('records a challenge left 8 minutes ago as abandoned at the time of its hold, alerts the owner and deletes its row', function () {
    Notification::fake();
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

it('records a challenge held exactly 7 minutes ago, and leaves one held a second later for a later sweep', function () {
    Notification::fake();
    $due = UserPendingChallenge::factory()->create(['created_at' => now()->subSeconds(7 * 60)]);
    $early = UserPendingChallenge::factory()->create(['created_at' => now()->subSeconds(7 * 60 - 1)]);

    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    $this->assertModelMissing($due);
    $this->assertModelExists($early);
    expect(UserSecurityEvent::query()->pluck('user_id')->all())->toBe([$due->user_id]);
});

it('runs every 5 minutes on one server, never overlapping itself for more than 10 minutes, with nothing for the app to register', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'keystone:alert-abandoned-challenges'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *')
        ->and($events->first()->onOneServer)->toBeTrue()
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(10);
});

it("records each of an account's abandoned challenges, and alerts its owner once about all of them", function () {
    Notification::fake();
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserEmail::factory()->for($grace, 'user')->verified()->create(['address' => 'grace@example.com']);
    UserPendingChallenge::factory()->for($ada, 'user')->count(3)->create(['created_at' => now()->subMinutes(9)]);
    UserPendingChallenge::factory()->for($grace, 'user')->create(['created_at' => now()->subMinutes(9)]);

    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    $counts = collect(Notification::sentNotifications()[AnonymousNotifiable::class])
        ->flatten(2)
        ->mapWithKeys(fn (array $sent) => [$sent['notifiable']->routes['mail'] => $sent['notification']->count]);

    expect($counts->all())->toEqualCanonicalizing(['ada@example.com' => 3, 'grace@example.com' => 1])
        ->and(UserSecurityEvent::query()->where('type', SecurityEventType::CHALLENGE_ABANDONED)->count())->toBe(4);
});

it('sends nothing more on the next sweep', function () {
    Notification::fake();
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserPendingChallenge::factory()->for($ada, 'user')->create(['created_at' => now()->subMinutes(9)]);
    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    $this->travel(5)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
    expect(UserSecurityEvent::query()->count())->toBe(1);
});

it('deletes the abandoned challenges of a deleted account without recording or mailing anything', function () {
    Notification::fake();
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserPendingChallenge::factory()->for($ada, 'user')->create(['created_at' => now()->subMinutes(9)]);
    $ada->delete();

    $this->artisan('keystone:alert-abandoned-challenges')->assertSuccessful();

    Notification::assertNothingSent();
    $this->assertDatabaseCount(UserPendingChallenge::class, 0);
    $this->assertDatabaseCount(UserSecurityEvent::class, 0);
});

it('sums up abandoned challenges, naming the first five IP addresses and devices and counting the rest', function () {
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    $sessionInfo = new SessionInfoFake;
    $devices = ['Chrome' => 'Mac', 'Firefox' => 'Linux', 'Safari' => 'iOS', 'Edge' => 'Windows', 'Opera' => 'Android', 'Vivaldi' => 'ChromeOS'];

    foreach ($devices as $browser => $platform) {
        $sessionInfo->knows("{$browser}/1.0", new Device(platform: $platform, browser: $browser));
    }

    $this->app->instance(SessionInfo::class, $sessionInfo);
    $this->app->instance(IpLocation::class, new IpLocationFake);
    $this->travelTo(CarbonImmutable::parse('2026-03-14 09:26:53', 'UTC'));
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserPendingChallenge::factory()->for($ada, 'user')->createMany([
        ['ip_address' => '203.0.113.1', 'user_agent' => 'Chrome/1.0', 'created_at' => now()->subMinutes(31)],
        ['ip_address' => '203.0.113.2', 'user_agent' => 'Firefox/1.0', 'created_at' => now()->subMinutes(29)],
        ['ip_address' => '203.0.113.3', 'user_agent' => 'Chrome/1.0', 'created_at' => now()->subMinutes(27)],
        ['ip_address' => '203.0.113.4', 'user_agent' => 'Safari/1.0', 'created_at' => now()->subMinutes(25)],
        ['ip_address' => '203.0.113.5', 'user_agent' => 'Edge/1.0', 'created_at' => now()->subMinutes(23)],
        ['ip_address' => '203.0.113.6', 'user_agent' => 'Opera/1.0', 'created_at' => now()->subMinutes(21)],
        ['ip_address' => '203.0.113.1', 'user_agent' => 'Vivaldi/1.0', 'created_at' => now()->subMinutes(19)],
        ['ip_address' => '203.0.113.7', 'user_agent' => 'Opera/1.0', 'created_at' => now()->subMinutes(17)],
    ]);

    $this->artisan('keystone:alert-abandoned-challenges');

    $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $body = $mail->getHtmlBody();
    $device = fn (string $browser) => e(__('keystone::alerts.device', ['device' => __('keystone::alerts.device-name', ['browser' => $browser, 'platform' => $devices[$browser]])]));

    expect($mail->getSubject())->toBe(__('keystone::alerts.challenge.abandoned.subject'))
        ->and($body)->toContain(e(trans_choice('keystone::alerts.challenge.abandoned.summary', 8)))
        ->and($body)->toContain(e(__('keystone::alerts.when', ['time' => '2026-03-14 08:55'])))
        ->and($body)->toContain(e(__('keystone::alerts.ip-address', ['ip_address' => '203.0.113.5'])))
        ->and($body)->not->toContain('203.0.113.6')
        ->and($body)->toContain(e(trans_choice('keystone::alerts.more-ip-addresses', 2)))
        ->and($body)->toContain($device('Edge'))
        ->and($body)->toContain($device('Opera'))
        ->and($body)->not->toContain('Vivaldi')
        ->and($body)->toContain(e(trans_choice('keystone::alerts.more-devices', 1)))
        ->and(substr_count($body, $device('Chrome')))->toBe(1);
});

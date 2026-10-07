<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

function alertedTo(SecurityEventType $type): array
{
    $addresses = [];

    Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $alert, array $channels, object $notifiable) use ($type, &$addresses) {
        $addresses[] = $notifiable->routes['mail'];

        return $alert->type === $type;
    });

    return $addresses;
}

describe('ending an account\'s sessions', function () {
    it('alerts the owner at every verified address', function () {
        Notification::fake();
        $account = $this->createAccount();
        $this->holdAddress($account, 'work@example.com');
        $this->holdAddress($account, 'old@example.com', verified: false);

        $this->artisan('keystone:end-sessions', ['user' => (string) $account->getKey()])->assertSuccessful();

        expect(alertedTo(SecurityEventType::SESSIONS_TERMINATED))->toBe(['jane@example.com', 'work@example.com']);
    });

    it('records the event without alerting when the operator suppresses the alert', function () {
        Notification::fake();
        $account = $this->createAccount();

        $this->artisan('keystone:end-sessions', ['user' => (string) $account->getKey(), '--no-alert' => true])->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('user_security_events', 1);
    });

    it('lets the job suppress the alert too', function () {
        Notification::fake();
        $account = $this->createAccount();

        EndSessions::dispatchSync($account, operator: 'jane@ops', alert: false);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('user_security_events', 1);
    });

    it('alerts nobody when every account\'s sessions end', function () {
        Notification::fake();
        $this->createAccount();

        $this->artisan('keystone:end-sessions', ['--all' => true])->assertSuccessful();

        Notification::assertNothingSent();
    });
});

describe('suspending and unsuspending an account', function () {
    it('alerts the owner at every verified address', function (string $command, ?string $suspendedAt, SecurityEventType $type) {
        Notification::fake();
        $account = $this->createAccount();
        $this->holdAddress($account, 'work@example.com');
        $this->holdAddress($account, 'old@example.com', verified: false);
        DB::table('users')->update(['suspended_at' => $suspendedAt]);

        $this->artisan($command, ['user' => (string) $account->getKey()])->assertSuccessful();

        expect(alertedTo($type))->toBe(['jane@example.com', 'work@example.com']);
    })->with([
        'suspending' => ['keystone:suspend', null, SecurityEventType::ACCOUNT_SUSPENDED],
        'unsuspending' => ['keystone:unsuspend', '2026-09-01 12:00:00', SecurityEventType::ACCOUNT_UNSUSPENDED],
    ]);
});

describe('a tripped limit', function () {
    it('alerts the owner once a window when wrong answers trip the failed-attempt limit', function () {
        Notification::fake();
        $this->createAccount();
        config(['keystone.rate_limits.failed_attempts_per_hour' => 3]);

        foreach (range(1, 6) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', 'secret' => 'wrong']);
        }

        expect(alertedTo(SecurityEventType::LIMIT_TRIPPED))->toBe(['jane@example.com']);
    });

    it('never alerts when a request limit trips', function () {
        $this->signInAccount(new FormTypeSupport);
        Notification::fake();

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view') + 1) as $ignored) {
            $this->get(route('login'));
        }

        $this->assertDatabaseHas('user_security_events', ['type' => 'limit.tripped', 'reason' => 'keystone.request_limit']);
        Notification::assertNothingSent();
    });
});

describe('the queued alert', function () {
    beforeEach(function () {
        config(['queue.default' => 'database', 'queue.failed.database' => config('database.default')]);
        Route::middleware(['web', 'auth'])->post('admin/end-my-sessions', fn () => EndSessions::dispatchSync(auth()->user(), 'jane@ops'));
    });

    it('holds no IP address or user agent in clear on the queue, or once it failed', function () {
        Mail::extend('broken', fn () => throw new RuntimeException('Mailer down.'));
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
        $this->signInAccount(new FormTypeSupport);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('User-Agent', FIREFOX)->post('admin/end-my-sessions');
        $queued = DB::table('jobs')->pluck('payload')->implode("\n");
        $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true])->run();
        $failed = DB::table('failed_jobs')->pluck('payload')->implode("\n");

        expect($queued)->toContain('SecurityAlert')->not->toContain('203.0.113.7', 'Firefox', 'Windows', 'jane@example.com')
            ->and($failed)->toContain('SecurityAlert')->not->toContain('203.0.113.7', 'Firefox', 'Windows', 'jane@example.com');
    });

    it('holds none of the IP addresses or user agents of an alert about several events in clear on the queue', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('User-Agent', FIREFOX)->passFirstFactor();
        session()->invalidate();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeader('User-Agent', 'curl/8.0')->passFirstFactor();
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $queued = DB::table('jobs')->pluck('payload')->implode("\n");
        expect(DB::table('jobs')->count())->toBe(1)
            ->and($queued)->toContain('SecurityAlert')->not->toContain('203.0.113.7', '198.51.100.9', 'Firefox', 'Windows', 'curl', 'jane@example.com');
    });
});

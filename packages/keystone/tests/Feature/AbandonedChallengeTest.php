<?php

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

const CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const FIREFOX_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

beforeEach(function () {
    Notification::fake();
});

/**
 * @return TestResponse<Response>
 */
function holdFrom(AppTestCase $test, ?string $device = null, string $ip = '203.0.113.1', string $userAgent = FIREFOX_ON_WINDOWS, string $address = 'jane@example.com'): TestResponse
{
    session()->invalidate();

    $test->fromDevice($device)->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $userAgent);

    return $test->passFirstFactor($address);
}

/**
 * @return TestResponse<Response>
 */
function answerChallenge(AppTestCase $test): TestResponse
{
    return $test->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
}

/**
 * @return list<array{string, SecurityAlert}>
 */
function abandonedChallengeAlerts(): array
{
    $sent = [];

    Notification::sent(new AnonymousNotifiable, SecurityAlert::class, function (SecurityAlert $alert, array $channels, AnonymousNotifiable $notifiable) use (&$sent) {
        if ($alert->type === SecurityEventType::CHALLENGE_ABANDONED) {
            $sent[] = [$notifiable->routes['mail'], $alert];
        }

        return true;
    });

    return $sent;
}

/**
 * @return list<SecurityEvent>
 */
function abandonedChallengeEvents(): array
{
    return SecurityEvent::query()->where('type', SecurityEventType::CHALLENGE_ABANDONED)->orderBy('id')->get()->all();
}

describe('a hold at the challenge', function () {
    it('writes a pending challenge with the account, the device and the IP address when the browser is not a known device', function () {
        $this->freezeSecond();
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));

        holdFrom($this, ip: '203.0.113.7', userAgent: FIREFOX_ON_WINDOWS)->assertRedirectToRoute('login.challenge');

        $row = DB::table('user_pending_challenges')->sole();
        expect($row->user_id)->toEqual($account->getKey())
            ->and(Crypt::decryptString($row->user_agent))->toBe(FIREFOX_ON_WINDOWS)
            ->and(Crypt::decryptString($row->ip_address))->toBe('203.0.113.7')
            ->and($row->created_at)->toBe(now()->toDateTimeString());
    });

    it('writes none when the browser is a known device of the account', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $device = $this->deviceCookieOf(answerChallenge($this));
        $this->post(route('logout'));

        holdFrom($this, $device)->assertRedirectToRoute('login.challenge');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('writes one when the browser is a known device of another account only', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $john = $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        holdFrom($this);
        $device = $this->deviceCookieOf(answerChallenge($this));
        $this->post(route('logout'));

        holdFrom($this, $device, address: 'john@example.com');

        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$john->getKey()]);
    });

    it('writes none for a sign-in held at enrollment', function () {
        $this->createFirstFactorAccount();
        config(['keystone.require_second_factor' => true]);

        holdFrom($this)->assertRedirectToRoute('login.enrollment');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('writes none for a sign-in that owes no challenge', function () {
        $this->createFirstFactorAccount();

        holdFrom($this)->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('still lands on the challenge when the pending challenge can\'t be written, reporting why', function () {
        Exceptions::fake();
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) {
            if (str_starts_with($query, 'insert into') && str_contains($query, 'user_pending_challenges')) {
                $connection->getPdo()->exec('select * from keystone_missing_table');
            }
        });

        holdFrom($this)->assertRedirectToRoute('login.challenge');

        expect(Keystone::guard()->pending()?->account->is($account))->toBeTrue();
        Exceptions::assertReported(PDOException::class);
    });

    it('cuts a long user agent to the length kept everywhere else', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));

        holdFrom($this, userAgent: str_repeat('a', 600));

        expect(Crypt::decryptString(DB::table('user_pending_challenges')->value('user_agent')))->toBe(str_repeat('a', 512));
    });
});

describe('the pending challenge', function () {
    it('is deleted when the challenge is passed', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        answerChallenge($this)->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is deleted when a recovery code passes the challenge', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        holdFrom($this);

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code])->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is deleted when the passed challenge leaves the sign-in held at enrollment', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        config(['keystone.require_recovery_codes' => true]);
        holdFrom($this);

        answerChallenge($this)->assertRedirectToRoute('login.enrollment');

        $this->assertGuest();
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('is kept when the answer is refused', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE));

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the sign-in is cancelled', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);

        $this->delete(route('login.challenge.cancel'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the pending sign-in expires', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travel(15)->minutes();

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is kept when the pending sign-in is voided', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->increment('credential_epoch');

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('is deleted when the challenge is passed after the same account passed its first factor again in the same session', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->passFirstFactor()->assertRedirectToRoute('login.challenge');

        answerChallenge($this)->assertRedirect('/');

        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('stays the first hold\'s when the same account passes its first factor again in the same session', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->travelTo('2026-10-06 12:03:00');

        $this->passFirstFactor()->assertRedirectToRoute('login.challenge');

        expect(DB::table('user_pending_challenges')->sole()->created_at)->toBe('2026-10-06 12:00:00');
    });

    it('is kept when a newer hold in the same session replaces its sign-in, and only the newer one is deleted on a pass', function () {
        $jane = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        $this->passFirstFactor();
        $this->passFirstFactor('john@example.com');

        answerChallenge($this)->assertRedirect('/');

        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$jane->getKey()]);
    });

    it('is kept when another account signs in over it in the same session', function () {
        $jane = $this->createChallengedAccount(new FormTypeSupport('code'));
        $john = $this->createFirstFactorAccount('john@example.com');
        $this->passFirstFactor();

        $this->passFirstFactor('john@example.com')->assertRedirect('/');

        $this->assertAuthenticatedAs($john);
        expect(DB::table('user_pending_challenges')->pluck('user_id')->all())->toEqual([$jane->getKey()]);
    });
});

describe('the sweep', function () {
    it('is scheduled once by core, every five minutes on one server', function () {
        $events = collect($this->app->make(Schedule::class)->events())->filter(fn (Event $event) => $event->description === 'keystone:sweep-abandoned-challenges');

        expect($events)->toHaveCount(1)
            ->and($events->sole()->expression)->toBe('*/5 * * * *')
            ->and($events->sole()->onOneServer)->toBeTrue();
    });

    it('never runs while an earlier run is still going, and takes over fifteen minutes after one that died', function () {
        $event = collect($this->app->make(Schedule::class)->events())->sole(fn (Event $event) => $event->description === 'keystone:sweep-abandoned-challenges');

        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(15);
    });

    it('deletes an account\'s rows before it turns to the next account, so a run that dies repeats one account at most', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $john = $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        holdFrom($this);
        holdFrom($this, address: 'john@example.com');
        $this->travelTo('2026-10-06 12:10:00');
        $left = null;
        EventFacade::listen(function (SecurityEventRecorded $recorded) use ($john, &$left) {
            if ($recorded->event->user_id === $john->getKey()) {
                $left = DB::table('user_pending_challenges')->pluck('user_id')->all();
            }
        });

        $this->artisan('schedule:run')->assertSuccessful();

        expect($left)->toEqual([$john->getKey()]);
    });

    it('dispatches each abandoned challenge to the app\'s listeners', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this, ip: '203.0.113.1');
        holdFrom($this, ip: '203.0.113.2');
        $this->travelTo('2026-10-06 12:10:00');
        $heard = [];
        EventFacade::listen(function (SecurityEventRecorded $recorded) use (&$heard) {
            $heard[] = [$recorded->event->type, $recorded->event->ip_address];
        });

        $this->artisan('schedule:run')->assertSuccessful();

        expect($heard)->toEqual([
            [SecurityEventType::CHALLENGE_ABANDONED, '203.0.113.1'],
            [SecurityEventType::CHALLENGE_ABANDONED, '203.0.113.2'],
        ]);
    });

    it('alerts the owner of an account that was soft-deleted meanwhile', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['deleted_at' => now()]);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toHaveCount(1)
            ->and(abandonedChallengeAlerts())->toHaveCount(1);
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('records a challenge left for seven minutes as abandoned at the time it was held, alerts the owner and deletes its row', function () {
        $this->travelTo('2026-10-06 12:03:00');
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this, ip: '203.0.113.7', userAgent: FIREFOX_ON_WINDOWS);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $event = SecurityEvent::query()->where('type', SecurityEventType::CHALLENGE_ABANDONED)->sole();
        expect($event->only(['user_id', 'actor', 'ip_address', 'user_agent', 'request_id', 'known_device']))->toEqual([
            'user_id' => $account->getKey(),
            'actor' => Actor::SYSTEM,
            'ip_address' => '203.0.113.7',
            'user_agent' => FIREFOX_ON_WINDOWS,
            'request_id' => null,
            'known_device' => null,
        ])->and($event->occurred_at->toDateTimeString())->toBe('2026-10-06 12:03:00');
        expect(abandonedChallengeAlerts())->toHaveCount(1)
            ->and(abandonedChallengeAlerts()[0][0])->toBe('jane@example.com');
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('leaves a challenge left for a second under seven minutes alone', function () {
        $this->travelTo('2026-10-06 12:03:01');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toBeEmpty()
            ->and(abandonedChallengeAlerts())->toBeEmpty();
        $this->assertDatabaseCount('user_pending_challenges', 1);
    });

    it('never alerts about a challenge passed before it ran', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:06:59');
        answerChallenge($this)->assertRedirect('/');
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toBeEmpty()
            ->and(abandonedChallengeAlerts())->toBeEmpty();
    });

    it('alerts about a challenge that was cancelled', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->delete(route('login.challenge.cancel'));
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toHaveCount(1)
            ->and(abandonedChallengeAlerts())->toHaveCount(1);
    });

    it('alerts nobody about a challenge held from a known device', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $device = $this->deviceCookieOf(answerChallenge($this));
        $this->post(route('logout'));
        holdFrom($this, $device);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toBeEmpty()
            ->and(abandonedChallengeAlerts())->toBeEmpty();
    });

    it('records each of an account\'s abandoned challenges and sends each recipient one alert about them all', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->holdAddress($account, 'work@example.com');
        holdFrom($this, ip: '203.0.113.7', userAgent: FIREFOX_ON_WINDOWS);
        holdFrom($this, ip: '198.51.100.9', userAgent: CHROME_ON_MAC);
        holdFrom($this, ip: '198.51.100.9', userAgent: CHROME_ON_MAC);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $alerts = abandonedChallengeAlerts();
        expect(array_map(fn (SecurityEvent $event) => $event->ip_address, abandonedChallengeEvents()))->toBe(['203.0.113.7', '198.51.100.9', '198.51.100.9'])
            ->and(array_column($alerts, 0))->toBe(['jane@example.com', 'work@example.com'])
            ->and($alerts[0][1]->count)->toBe(3)
            ->and($alerts[0][1]->ipAddresses)->toBe(['203.0.113.7', '198.51.100.9'])
            ->and($alerts[0][1]->devices)->toBe(['Firefox on Windows', 'Chrome on Mac']);
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('dates each abandoned challenge by its hold, and the alert by the earliest of them', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:02:00');
        holdFrom($this);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(array_map(fn (SecurityEvent $event) => $event->occurred_at->toDateTimeString(), abandonedChallengeEvents()))->toBe(['2026-10-06 12:00:00', '2026-10-06 12:02:00'])
            ->and(abandonedChallengeAlerts()[0][1]->occurredAt->toDateTimeString())->toBe('2026-10-06 12:00:00');
    });

    it('mails only what is true when it runs, never that nobody was signed in, since the sign-in can still be finished', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:10:00');
        $this->artisan('schedule:run')->assertSuccessful();
        [$address, $alert] = abandonedChallengeAlerts()[0];

        $mail = $alert->toMail((new AnonymousNotifiable)->route('mail', $address));

        expect((string) $mail->render())->toContain(e(__('keystone::alerts.types.challenge.abandoned.what')))
            ->not->toContain('Nobody was signed in')
            ->and($mail->subject)->toBe(__('keystone::alerts.types.challenge.abandoned.subject'));
    });

    it('mails the first five IP addresses of an account\'s abandoned challenges and how many more there were', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        collect(range(1, 7))->each(fn (int $host) => holdFrom($this, ip: "203.0.113.{$host}"));
        $this->travelTo('2026-10-06 12:10:00');
        $this->artisan('schedule:run')->assertSuccessful();
        [$address, $alert] = abandonedChallengeAlerts()[0];

        $mail = (string) $alert->toMail((new AnonymousNotifiable)->route('mail', $address))->render();

        expect($mail)->toContain(e(__('keystone::alerts.more', ['values' => '203.0.113.1, 203.0.113.2, 203.0.113.3, 203.0.113.4, 203.0.113.5', 'count' => 2])))
            ->not->toContain('203.0.113.6', '203.0.113.7', 'keystone::alerts.more');
    });

    it('sends each account its own alert about its own challenges', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        holdFrom($this, ip: '203.0.113.7');
        holdFrom($this, ip: '198.51.100.9', address: 'john@example.com');
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $alerts = abandonedChallengeAlerts();
        expect(array_map(fn (array $sent) => [$sent[0], $sent[1]->count, $sent[1]->ipAddresses], $alerts))->toBe([
            ['jane@example.com', 1, ['203.0.113.7']],
            ['john@example.com', 1, ['198.51.100.9']],
        ]);
    });

    it('sweeps only the challenges that are old enough, leaving an account\'s newer one for a later run', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this, ip: '203.0.113.7');
        $this->travelTo('2026-10-06 12:05:00');
        holdFrom($this, ip: '198.51.100.9');
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeAlerts())->toHaveCount(1)
            ->and(abandonedChallengeAlerts()[0][1]->ipAddresses)->toBe(['203.0.113.7'])
            ->and(Crypt::decryptString(DB::table('user_pending_challenges')->sole()->ip_address))->toBe('198.51.100.9');
    });

    it('loses the rows of a hard-deleted account with the account, so nobody is told', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->createChallengedAccount(new FormTypeSupport('code'), 'john@example.com');
        holdFrom($this);
        holdFrom($this, address: 'john@example.com');
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->delete();
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toHaveCount(1)
            ->and(array_column(abandonedChallengeAlerts(), 0))->toBe(['john@example.com']);
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('still records the events and deletes the rows when the alert is silenced', function () {
        config(['keystone.notifications' => [...config('keystone.notifications'), 'challenge.abandoned' => null]]);
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:10:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toHaveCount(1)
            ->and(abandonedChallengeAlerts())->toBeEmpty();
        $this->assertDatabaseCount('user_pending_challenges', 0);
    });

    it('alerts once about a challenge, however often it runs', function () {
        $this->travelTo('2026-10-06 12:00:00');
        $this->createChallengedAccount(new FormTypeSupport('code'));
        holdFrom($this);
        $this->travelTo('2026-10-06 12:10:00');
        $this->artisan('schedule:run')->assertSuccessful();
        $this->travelTo('2026-10-06 12:15:00');

        $this->artisan('schedule:run')->assertSuccessful();

        expect(abandonedChallengeEvents())->toHaveCount(1)
            ->and(abandonedChallengeAlerts())->toHaveCount(1);
    });
});

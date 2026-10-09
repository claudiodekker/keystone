<?php

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Device;
use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\SessionInfo;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

pest()->extend(AppTestCase::class);

const LAPTOP_AGENT = 'Laptop/1.0';

const PHONE_AGENT = 'Phone/1.0';

const PHONE_ADDRESS = '198.51.100.7';

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code']]);
    Route::middleware('web')->get('who-is-signed-in', fn () => (string) (auth()->id() ?? 'guest'));

    $this->app->instance(SessionInfo::class, new class implements SessionInfo
    {
        public function describe(string $userAgent): ?Device
        {
            return match ($userAgent) {
                LAPTOP_AGENT => new Device(platform: 'Windows', browser: 'Firefox'),
                PHONE_AGENT => new Device(platform: 'iOS', browser: 'Safari'),
                default => null,
            };
        }
    });
});

/**
 * Sign the account in on this browser from the laptop, on the database session driver.
 *
 * @return Model&KeystoneUser
 */
function signInOnLaptop(AppTestCase $test, string $address = 'jane@example.com'): Model
{
    $test->useSessionDriver('database');
    $test->withHeader('User-Agent', LAPTOP_AGENT)->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);

    return $test->signInAccount(new FormTypeSupport, $address);
}

/**
 * Sign the address in from the named browser, from the agent and the IP address, ticking remember-me, and get the id of the session it got.
 */
function signInRememberedFrom(AppTestCase $test, string $browser, string $agent = PHONE_AGENT, string $ipAddress = PHONE_ADDRESS, string $address = 'jane@example.com'): string
{
    return $test->inBrowser($browser, function () use ($test, $agent, $ipAddress, $address) {
        $test->withHeader('User-Agent', $agent)->withServerVariables(['REMOTE_ADDR' => $ipAddress]);

        $response = $test->post(route('login.submit', ['type' => 'form']), [
            'identifier' => $address,
            ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
            'remember' => '1',
        ]);

        $test->fromRememberCookie($test->rememberCookieOf($response));

        return session()->getId();
    });
}

/**
 * Get who the named browser is signed in as on its next request, or "guest".
 */
function signedInOn(AppTestCase $test, string $browser): string
{
    return $test->inBrowser($browser, fn () => $test->get('who-is-signed-in')->getContent());
}

/**
 * Get what the session with the id stored.
 *
 * @return array<string, mixed>
 */
function storedSession(string $sessionId): array
{
    return unserialize(base64_decode(DB::table('sessions')->where('id', $sessionId)->value('payload')));
}

/**
 * Store the session with the id as holding the values.
 *
 * @param  array<string, mixed>  $values
 */
function storeInSession(string $sessionId, array $values): void
{
    DB::table('sessions')->where('id', $sessionId)->update(['payload' => base64_encode(serialize([...storedSession($sessionId), ...$values]))]);
}

/**
 * Get the id of the remember token the session stored.
 */
function rememberTokenOf(string $sessionId): int
{
    return storedSession($sessionId)['keystone_remember_web'];
}

describe('the sessions list', function () {
    it('lists this device first, then each other session with its device, address, location and last activity', function () {
        $this->freezeSecond();
        $account = signInOnLaptop($this);
        $signedInAt = now();
        signInRememberedFrom($this, 'phone');
        $this->travel(5)->minutes();
        $this->app->instance(IpLocation::class, Mockery::mock(IpLocation::class, function ($mock) {
            $mock->shouldReceive('locate')->once()->with('203.0.113.5')->andReturn('Amsterdam, Netherlands');
            $mock->shouldReceive('locate')->once()->with(PHONE_ADDRESS)->andReturn('Paris, France');
        }));

        $response = $this->get(route('security'));

        $response->assertOk()
            ->assertJsonPath('sessionsStatus', null)
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.platform', 'Windows')
            ->assertJsonPath('sessions.0.browser', 'Firefox')
            ->assertJsonPath('sessions.0.ipAddress', '203.0.113.5')
            ->assertJsonPath('sessions.0.location', 'Amsterdam, Netherlands')
            ->assertJsonPath('sessions.0.lastActiveAt', now()->toIso8601String())
            ->assertJsonPath('sessions.0.current', true)
            ->assertJsonPath('sessions.1.platform', 'iOS')
            ->assertJsonPath('sessions.1.browser', 'Safari')
            ->assertJsonPath('sessions.1.ipAddress', PHONE_ADDRESS)
            ->assertJsonPath('sessions.1.location', 'Paris, France')
            ->assertJsonPath('sessions.1.lastActiveAt', $signedInAt->toIso8601String())
            ->assertJsonPath('sessions.1.current', false);
        expect($response->json('sessions.0.handle'))->toMatch('/^[0-9a-f]{64}$/')
            ->and($response->json('sessions.1.handle'))->toMatch('/^[0-9a-f]{64}$/')
            ->not->toBe($response->json('sessions.0.handle'));
        $this->assertAuthenticatedAs($account);
    });

    it('lists a session that stored no IP address with none, locating nothing for it', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        DB::table('sessions')->where('id', $phone)->update(['ip_address' => null]);
        $this->app->instance(IpLocation::class, Mockery::mock(IpLocation::class, function ($mock) {
            $mock->shouldReceive('locate')->once()->with('203.0.113.5')->andReturn(null);
        }));

        $response = $this->get(route('security'));

        $response->assertJsonPath('sessions.1.ipAddress', null)->assertJsonPath('sessions.1.location', null);
    });

    it('lists a session whose location lookup failed with none, reporting the failure', function () {
        Exceptions::fake();
        signInOnLaptop($this);
        signInRememberedFrom($this, 'phone');
        $this->app->instance(IpLocation::class, Mockery::mock(IpLocation::class)->shouldReceive('locate')->andThrow(new RuntimeException('Lookup failed.'))->getMock());

        $response = $this->get(route('security'));

        $response->assertOk()->assertJsonPath('sessions.1.location', null);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Lookup failed.');
    });

    it('locates each IP address once', function () {
        signInOnLaptop($this);
        signInRememberedFrom($this, 'phone');
        signInRememberedFrom($this, 'tablet');
        $this->app->instance(IpLocation::class, Mockery::mock(IpLocation::class, function ($mock) {
            $mock->shouldReceive('locate')->once()->with('203.0.113.5')->andReturn('Amsterdam, Netherlands');
            $mock->shouldReceive('locate')->once()->with(PHONE_ADDRESS)->andReturn('Paris, France');
        }));

        $response = $this->get(route('security'));

        $response->assertJsonCount(3, 'sessions')
            ->assertJsonPath('sessions.1.location', 'Paris, France')
            ->assertJsonPath('sessions.2.location', 'Paris, France');
    });

    it('hides a session an epoch move ended, even one whose row was written after the move', function (bool $writtenAfter) {
        $account = signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $credential = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'code']);
        $this->travel(1)->minute();
        $this->delete(route('security.credentials.remove.submit', ['credential' => $credential]));

        if ($writtenAfter) {
            DB::table('sessions')->where('id', $phone)->update(['last_activity' => now()->getTimestamp()]);
        }

        $response = $this->get(route('security'));

        $this->assertDatabaseHas('sessions', ['id' => $phone]);
        $response->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.current', true);
    })->with([
        'written before the move' => [false],
        'written after the move' => [true],
    ]);

    it('hides a session idle past the session lifetime', function () {
        signInOnLaptop($this);
        signInRememberedFrom($this, 'phone');
        $this->travel(config()->integer('session.lifetime') - 1)->minutes();
        $this->get(route('security'));
        $this->travel(2)->minutes();

        $response = $this->get(route('security'));

        $response->assertJsonCount(1, 'sessions');
    });

    it('lists no other account\'s sessions', function () {
        signInOnLaptop($this);
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        signInRememberedFrom($this, 'johns-phone', address: 'john@example.com');

        $response = $this->get(route('security'));

        $response->assertJsonCount(1, 'sessions');
    });

    it('lists no session signed in as another account, whatever its row says', function () {
        $account = signInOnLaptop($this);
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $johns = signInRememberedFrom($this, 'johns-phone', address: 'john@example.com');
        DB::table('sessions')->where('id', $johns)->update(['user_id' => $account->getKey()]);

        $response = $this->get(route('security'));

        $response->assertJsonCount(1, 'sessions');
    });

    it('hides a session whose stored data can\'t be read', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        DB::table('sessions')->where('id', $phone)->update(['payload' => base64_encode('unreadable')]);

        $response = $this->get(route('security'));

        $response->assertJsonCount(1, 'sessions');
    });

    it('lists the 50 most recently active sessions, this device among them', function () {
        $this->freezeSecond();
        signInOnLaptop($this);
        $phone = DB::table('sessions')->where('id', signInRememberedFrom($this, 'phone'))->first();
        DB::table('sessions')->insert(array_map(fn (int $minutesAgo) => [
            ...(array) $phone,
            'id' => Str::random(40),
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ], range(60, 1)));

        $response = $this->get(route('security'));

        $response->assertJsonCount(50, 'sessions')
            ->assertJsonPath('sessions.0.current', true)
            ->assertJsonPath('sessions.1.lastActiveAt', now()->toIso8601String())
            ->assertJsonPath('sessions.49.lastActiveAt', now()->subMinutes(48)->toIso8601String());
    });

    it('never sends a session\'s id', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');

        $response = $this->get(route('security'));

        $response->assertDontSee($phone)->assertDontSee(session()->getId());
    });

    it('never sends a session\'s id from the confirm step', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');

        $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]));

        $response->assertOk()->assertDontSee($phone)->assertDontSee(session()->getId());
    });

    it('says the sessions can\'t be listed on a driver that keeps no table of them', function (string $driver) {
        $this->useSessionDriver($driver);
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security'));

        $response->assertJsonPath('sessions', [])->assertJsonPath('sessionsStatus', __('keystone::messages.status.sessions-unavailable'));
    })->with(['array', 'file']);
});

describe('the confirm step', function () {
    it('shows the session', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');

        $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]));

        $response->assertOk()
            ->assertJsonPath('page', 'session-revocation')
            ->assertJsonPath('session.handle', $this->handleOfSession($phone))
            ->assertJsonPath('session.browser', 'Safari')
            ->assertJsonPath('session.ipAddress', PHONE_ADDRESS)
            ->assertJsonPath('session.current', false)
            ->assertDontSee($phone);
    });

    it('refuses this device\'s session', function () {
        signInOnLaptop($this);

        $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession(session()->getId())]));

        $response->assertRedirectToRoute('security')->assertSessionHasErrors(['session' => __('keystone::messages.current_session')]);
    });

    it('says a handle no listed session has was not found', function () {
        signInOnLaptop($this);

        $this->get(route('security.sessions.revoke', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('security');

        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.session-not-found'));
    });

    it('says the sessions can\'t be listed on a driver that keeps no table of them', function () {
        $this->useSessionDriver('file');
        $this->signInAccount(new FormTypeSupport);

        $this->get(route('security.sessions.revoke', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('security');

        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.sessions-unavailable'));
    });

    it('asks for sudo first', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)], absolute: false));
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.sessions.revoke', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('login');
    });

    it('limits requests to the page', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]))->assertOk();
        }

        $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]))->assertTooManyRequests();
    });
});

describe('revoking a session', function () {
    it('signs out that browser, remember-me cookie and all, leaving the other sessions and the credential epoch alone', function () {
        $account = signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $tablet = signInRememberedFrom($this, 'tablet');
        [$phoneToken, $tabletToken] = [rememberTokenOf($phone), rememberTokenOf($tablet)];

        $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('sessions', ['id' => $phone]);
        $this->assertDatabaseHas('sessions', ['id' => $tablet]);
        $this->assertDatabaseMissing('user_remember_tokens', ['id' => $phoneToken]);
        $this->assertDatabaseHas('user_remember_tokens', ['id' => $tabletToken]);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        expect(signedInOn($this, 'phone'))->toBe('guest')
            ->and(signedInOn($this, 'tablet'))->toBe((string) $account->getKey());
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.session-revoked'))->assertJsonCount(2, 'sessions');
        $this->assertAuthenticatedAs($account);
    });

    it('records it with that session\'s IP address and user agent, and alerts the owner', function () {
        $account = signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        Notification::fake();

        $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $event = SecurityEvent::query()->where('type', 'session.revoked')->sole();
        expect($event->only(['user_id', 'actor', 'ip_address', 'user_agent']))->toEqual([
            'user_id' => $account->getKey(),
            'actor' => Actor::USER,
            'ip_address' => PHONE_ADDRESS,
            'user_agent' => PHONE_AGENT,
        ])->and($event->request_id)->not->toBeNull();
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SESSION_REVOKED);
    });

    it('says a session revoked a moment ago was not found, recording nothing more', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $response->assertRedirectToRoute('security');
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.session-not-found'));
        expect(SecurityEvent::query()->where('type', 'session.revoked')->count())->toBe(1);
    });

    it('refuses another account\'s session, even by its handle', function () {
        signInOnLaptop($this);
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $johns = signInRememberedFrom($this, 'johns-phone', address: 'john@example.com');

        $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($johns)]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseHas('sessions', ['id' => $johns]);
        $this->assertDatabaseHas('user_remember_tokens', ['id' => rememberTokenOf($johns)]);
        expect(signedInOn($this, 'johns-phone'))->toBe((string) $john->getKey());
    });

    it('forgets only a remember token of the account, whatever the session stored', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $john = $this->createAccount('john@example.com');
        $this->arrangeCredential($john, new FormTypeSupport, Surface::SIGN_IN);
        $johnsToken = rememberTokenOf(signInRememberedFrom($this, 'johns-phone', address: 'john@example.com'));
        storeInSession($phone, ['keystone_remember_web' => $johnsToken]);

        $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $this->assertDatabaseMissing('sessions', ['id' => $phone]);
        $this->assertDatabaseHas('user_remember_tokens', ['id' => $johnsToken]);
    });

    it('refuses this device\'s session, revoking nothing', function () {
        $account = signInOnLaptop($this);

        $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession(session()->getId())]));

        $response->assertRedirectToRoute('security')->assertSessionHasErrors(['session' => __('keystone::messages.current_session')]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'session.revoked']);
        $this->assertAuthenticatedAs($account);
    });

    it('refuses on a driver that keeps no table of them', function (string $driver) {
        $this->useSessionDriver($driver);
        $this->signInAccount(new FormTypeSupport);

        $this->delete(route('security.sessions.revoke.submit', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('security');

        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.sessions-unavailable'));
    })->with(['array', 'file']);

    it('asks for sudo first, revoking nothing', function () {
        signInOnLaptop($this);
        $phone = signInRememberedFrom($this, 'phone');
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('sessions', ['id' => $phone]);
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.sessions.revoke.submit', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        signInOnLaptop($this);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.sessions.revoke.submit', ['session' => str_repeat('a', 64)]))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.sessions.revoke.submit', ['session' => str_repeat('a', 64)]))->assertTooManyRequests();
    });
});

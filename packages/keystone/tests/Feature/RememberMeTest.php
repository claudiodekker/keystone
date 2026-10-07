<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\RememberTokens;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->extend(AppTestCase::class);

class AsksWhoIsSignedIn
{
    public function handle(Request $request, Closure $next): mixed
    {
        auth()->check();

        return $next($request);
    }
}

beforeEach(function () {
    Notification::fake();
    Route::middleware('web')->get('whoami', fn () => (string) (auth()->id() ?? 'guest'));
    Route::middleware(['web', 'auth'])->get('dashboard', fn () => 'The dashboard.');
    Route::middleware(['web', 'auth'])->post('end-my-sessions', fn () => EndSessions::dispatchSync(auth()->user()));
    Route::middleware('web')->get('via-remember', fn () => auth()->check() && auth()->viaRemember() ? 'remembered' : 'not remembered');
});

/**
 * Submit the address's valid first factor with the remember-me box ticked, or posting the given value for it.
 *
 * @return TestResponse<Response>
 */
function signInTicked(AppTestCase $test, string $address = 'jane@example.com', mixed $remember = '1'): TestResponse
{
    return $test->post(route('login.submit', ['type' => 'form']), [
        'identifier' => $address,
        ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
        'remember' => $remember,
    ]);
}

/**
 * Sign the address in with the box ticked, returning the values of the remember-me and device cookies the browser was handed.
 *
 * @return array{string, string}
 */
function tickedBrowser(AppTestCase $test, string $address = 'jane@example.com'): array
{
    $response = signInTicked($test, $address);

    return [$test->rememberCookieOf($response), $test->deviceCookieOf($response)];
}

/**
 * Come back in a browser whose session is gone, holding the remember-me and device cookies' values, or none.
 */
function comeBack(AppTestCase $test, ?string $remember, ?string $device = null): void
{
    session()->invalidate();

    $test->fromRememberCookie($remember)->fromDevice($device);
}

/**
 * Get the recorded events of the type, oldest first.
 *
 * @return Collection<int, SecurityEvent>
 */
function recordedEvents(SecurityEventType $type): Collection
{
    return SecurityEvent::query()->where('type', $type)->orderBy('id')->get();
}

/**
 * Count the alerts sent about a sign-in from a new device.
 */
function signInAlerts(): int
{
    return Notification::sent(new AnonymousNotifiable, SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::SIGNED_IN)->count();
}

describe('a completed sign-in', function () {
    it('hands a ticked sign-in a host-only, secure, http-only, lax cookie of 64 hex characters that the framework does not encrypt', function () {
        $this->createFirstFactorAccount();

        $response = signInTicked($this);

        $cookie = $response->getCookie(RememberTokens::COOKIE, decrypt: false);
        expect($cookie->getName())->toBe('__Host-keystone_remember')
            ->and($cookie->getValue())->toMatch('/^[0-9a-f]{64}$/')
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    });

    it('stores only a digest of the value, with the credential epoch and an expiry the cookie shares', function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        DB::table('users')->update(['credential_epoch' => 7]);

        $response = signInTicked($this);

        $row = DB::table('user_remember_tokens')->sole();
        expect($row->user_id)->toEqual($account->getKey())
            ->and($row->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)))
            ->and($row->credential_epoch)->toEqual(7)
            ->and(strtotime($row->expires_at))->toBe(now()->addSeconds(600)->getTimestamp())
            ->and($response->getCookie(RememberTokens::COOKIE, decrypt: false)->getExpiresTime())->toBe(now()->addSeconds(600)->getTimestamp());
    });

    it('keeps the id of the token it issued in the session', function () {
        $this->createFirstFactorAccount();

        signInTicked($this);

        expect(session('keystone_remember_web'))->toBe(DB::table('user_remember_tokens')->value('id'));
    });

    it('issues nothing when the box was not ticked', function () {
        $account = $this->createFirstFactorAccount();

        $response = $this->passFirstFactor();

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull()
            ->and(session('keystone_remember_web'))->toBeNull();
    });

    it('signs in without remembering when the posted value is not a tick', function (mixed $remember) {
        $account = $this->createFirstFactorAccount();

        $response = signInTicked($this, remember: $remember);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    })->with(['zero' => ['0'], 'an empty string' => [''], 'a word' => ['always'], 'a list' => [['1']]]);

    it('issues nothing when the sign-in is refused', function () {
        $this->createFirstFactorAccount();

        $response = $this->post(route('login.submit', ['type' => 'form']), [
            'identifier' => 'jane@example.com',
            ...(new FormTypeSupport)->rejectedProof(Surface::SIGN_IN),
            'remember' => '1',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    });

    it('issues nothing while the sign-in is held, and remembers it once the challenge completes it', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);

        $held = signInTicked($this);

        $held->assertRedirectToRoute('login.challenge');
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($held))->toBeNull();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)))
            ->and(session('keystone_remember_web'))->toBe(DB::table('user_remember_tokens')->value('id'));
    });

    it('does not remember a challenged sign-in whose box was not ticked, whatever the challenge posts', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), [...$code->validProof(Surface::CHALLENGE), 'remember' => '1']);

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull();
    });

    it('remembers a ticked sign-in once the enrollment it was held for completes it', function () {
        config(['keystone.require_second_factor' => true]);
        $account = $this->createFirstFactorAccount();

        $held = signInTicked($this);

        $held->assertRedirectToRoute('login.enrollment');
        $this->assertDatabaseCount('user_remember_tokens', 0);

        $response = $this->enrollSecondFactor(new FormTypeSupport('code'));

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)));
    });

    it('remembers a ticked sign-in that passes the challenge and then saves the recovery codes it owes', function () {
        $code = new FormTypeSupport('code');
        $account = $this->createChallengedAccount($code);
        config(['keystone.require_recovery_codes' => true]);
        signInTicked($this);
        $this->post(route('login.challenge.submit', ['type' => 'code']), $code->validProof(Surface::CHALLENGE));
        $this->assertDatabaseCount('user_remember_tokens', 0);
        $this->get(route('login.recovery-codes'));

        $response = $this->post(route('login.recovery-codes.submit'), ['code' => $this->stagedRecoveryCodes()[0]]);

        $this->assertAuthenticatedAs($account);
        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)));
    });
});

describe('remember-me turned off', function () {
    beforeEach(function () {
        config(['keystone.remember.lifetime_seconds' => 0]);
    });

    it('ignores a ticked box', function () {
        $account = $this->createFirstFactorAccount();

        $response = signInTicked($this);

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect($this->rememberCookieOf($response))->toBeNull()
            ->and(session('keystone_remember_web'))->toBeNull();
    });

    it('does not read a cookie it issued earlier, and restores from it again once turned back on', function () {
        config(['keystone.remember.lifetime_seconds' => 2592000]);
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        config(['keystone.remember.lifetime_seconds' => 0]);
        comeBack($this, $remember, $device);

        $off = $this->get('whoami');

        $off->assertContent('guest');
        expect($off->getCookie(RememberTokens::COOKIE, decrypt: false))->toBeNull();
        $this->assertDatabaseCount('user_remember_tokens', 1);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'request.rejected']);

        config(['keystone.remember.lifetime_seconds' => 2592000]);

        $this->get('whoami')->assertContent((string) $account->getKey());
    });

    it('tells the sign-in page not to offer the box', function () {
        $response = $this->get(route('login'));

        $response->assertJsonPath('rememberOffered', false);
    });
});

describe('the sign-in page', function () {
    it('is told to offer the box while remember-me is on', function () {
        $response = $this->get(route('login'));

        $response->assertJsonPath('rememberOffered', true);
    });
});

describe('a sign-out', function () {
    it('forgets this browser\'s token and drops its cookie, leaving the account\'s other devices theirs', function () {
        $account = $this->createFirstFactorAccount();
        signInTicked($this);
        $phone = DB::table('user_remember_tokens')->value('id');
        session()->invalidate();
        $laptop = $this->rememberCookieOf(signInTicked($this));
        $this->fromRememberCookie($laptop);

        $response = $this->post(route('logout'));

        $response->assertCookieExpired(RememberTokens::COOKIE);
        $cookie = $response->getCookie(RememberTokens::COOKIE, decrypt: false);
        expect(DB::table('user_remember_tokens')->pluck('id')->all())->toBe([$phone])
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    });

    it('sets no cookie in a browser that holds none', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->post(route('logout'));

        expect($response->getCookie(RememberTokens::COOKIE, decrypt: false))->toBeNull();
    });
});

describe('a return', function () {
    it('restores the sign-in on a new session id, stamped with the epoch, the time and the token that remembers it', function () {
        $this->freezeSecond();
        $account = $this->createFirstFactorAccount();
        DB::table('users')->update(['credential_epoch' => 7]);
        [$remember, $device] = tickedBrowser($this);
        $this->travel(90)->seconds();
        comeBack($this, $remember, $device);
        $sessionId = session()->getId();

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
        expect(session()->getId())->not->toBe($sessionId)
            ->and(session('keystone_epoch_web'))->toBe(7)
            ->and(session('keystone_signed_in_at_web'))->toBe(now()->getTimestamp())
            ->and(session('keystone_remember_web'))->toBe(DB::table('user_remember_tokens')->value('id'));
    });

    it('records the restored sign-in as remembered, with no credential type', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        comeBack($this, $remember, $device);

        $this->get('whoami');

        expect(recordedEvents(SecurityEventType::SIGNED_IN)->last()->only(['user_id', 'reason', 'credential_type', 'known_device']))->toEqual([
            'user_id' => $account->getKey(),
            'reason' => 'remembered',
            'credential_type' => null,
            'known_device' => true,
        ]);
    });

    it('changes neither the token nor either cookie', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $row = DB::table('user_remember_tokens')->sole();
        comeBack($this, $remember, $device);

        $response = $this->get('whoami');

        expect(DB::table('user_remember_tokens')->sole())->toEqual($row)
            ->and($response->getCookie(RememberTokens::COOKIE, decrypt: false))->toBeNull()
            ->and($this->deviceCookieOf($response))->toBeNull();
    });

    it('never sees the sign-in form, and cannot sign in again over the restored session', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        comeBack($this, $remember, $device);

        $page = $this->get(route('login'));
        comeBack($this, $remember, $device);
        $submitted = signInTicked($this);

        $page->assertRedirect('/');
        $submitted->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_remember_tokens', 1);
    });

    it('fires the framework\'s login event as remembered, and answers that the request came in by remember-me', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        comeBack($this, $remember, $device);
        $remembered = [];
        Event::listen(Login::class, function (Login $login) use (&$remembered) {
            $remembered[] = $login->remember;
        });

        $response = $this->get('via-remember');
        $next = $this->get('via-remember');

        $response->assertContent('remembered');
        $next->assertContent('not remembered');
        expect($remembered)->toBe([true]);
    });

    it('restores the sign-in when recording it fails', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        comeBack($this, $remember);
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
    });

    it('waits for the session to start, so a restore never lands on the session id the browser sent', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        comeBack($this, $remember, $device);
        $sessionId = session()->getId();
        $this->app->make(Kernel::class)->prependMiddleware(AsksWhoIsSignedIn::class);

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
        expect(session()->getId())->not->toBe($sessionId)
            ->and(recordedEvents(SecurityEventType::SIGNED_IN))->toHaveCount(2);
    });

    it('keeps restoring until the lifetime counted from the sign-in ends, however often the cookie is used', function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $expiresAt = DB::table('user_remember_tokens')->value('expires_at');

        foreach ([200, 200, 199] as $seconds) {
            $this->travel($seconds)->seconds();
            comeBack($this, $remember, $device);

            $this->get('whoami')->assertContent((string) $account->getKey());
        }

        expect(DB::table('user_remember_tokens')->value('expires_at'))->toBe($expiresAt);
        $this->travel(1)->seconds();
        comeBack($this, $remember, $device);

        $this->get('whoami')->assertContent('guest');
    });

    it('keeps the lifetime a token was issued with when the setting is raised later', function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        config(['keystone.remember.lifetime_seconds' => 6000]);
        $this->travel(600)->seconds();
        comeBack($this, $remember, $device);

        $response = $this->get('whoami');

        $response->assertContent('guest');
    });

    it('restores on one device after another device of the account signed out', function () {
        $account = $this->createFirstFactorAccount();
        [$phone, $phoneDevice] = tickedBrowser($this);
        comeBack($this, null);
        [$laptop] = tickedBrowser($this);
        $this->fromRememberCookie($laptop)->post(route('logout'));
        comeBack($this, $phone, $phoneDevice);

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
    });

    it('does not restore from the cookie a sign-out dropped', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->post(route('logout'));
        comeBack($this, $remember, $device);

        $response = $this->get('whoami');

        $response->assertContent('guest');
    });

    it('leaves one token for a browser that signs in again after its cookie died', function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->travel(600)->seconds();
        comeBack($this, $remember, $device);

        $response = signInTicked($this);

        expect(DB::table('user_remember_tokens')->sole()->token_hash)->toBe(hash('sha256', $this->rememberCookieOf($response)));
    });
});

describe('a dead cookie', function () {
    it('never restores, loses its token and its cookie, and is recorded against the account', function (Closure $kill) {
        $this->freezeSecond();
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        session()->invalidate();
        $kill($this, $account);
        comeBack($this, $remember, $device);

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login')
            ->assertCookieExpired(RememberTokens::COOKIE)
            ->assertHeaderMissing('Clear-Site-Data');
        $this->assertGuest();
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect(recordedEvents(SecurityEventType::REQUEST_REJECTED)->sole()->only(['user_id', 'reason']))->toEqual([
            'user_id' => $account->getKey(),
            'reason' => 'keystone.dead_remember_cookie',
        ])->and(session('keystone.status'))->toBeNull();
    })->with([
        'an older credential epoch' => [fn (AppTestCase $test, $account) => $test->artisan('keystone:end-sessions', ['user' => (string) $account->getKey()])->assertSuccessful()],
        'a suspended account' => [fn (AppTestCase $test, $account) => $test->artisan('keystone:suspend', ['user' => (string) $account->getKey()])->assertSuccessful()],
        'an account suspended on the same credential epoch' => [fn () => DB::table('users')->update(['suspended_at' => now()])],
        'an invalidated account' => [fn () => DB::table('users')->update(['invalidated_at' => now()])],
        'a deleted account' => [fn () => DB::table('users')->update(['deleted_at' => now()])],
        'an expired token' => [fn (AppTestCase $test) => $test->travel(config()->integer('keystone.remember.lifetime_seconds'))->seconds()],
    ]);

    it('is dropped and recorded against nobody when no token has its value', function (Closure $forget) {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $forget($account);
        comeBack($this, $remember, $device);
        $rejected = [];
        Event::listen(SecurityEventRecorded::class, function (SecurityEventRecorded $recorded) use (&$rejected) {
            $rejected[] = $recorded->event->only(['type', 'user_id', 'reason']);
        });

        $response = $this->get('whoami');

        $response->assertContent('guest')->assertCookieExpired(RememberTokens::COOKIE);
        expect($rejected)->toEqual([['type' => SecurityEventType::REQUEST_REJECTED, 'user_id' => null, 'reason' => 'keystone.dead_remember_cookie']]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'request.rejected']);
    })->with([
        'a forgotten token' => [fn () => DB::table('user_remember_tokens')->delete()],
        'an account that is gone' => [fn ($account) => DB::table('users')->where('id', $account->getKey())->delete()],
    ]);

    it('is left alone when its value is not one Keystone hands out', function (string $value) {
        $this->createFirstFactorAccount();
        comeBack($this, null);
        $this->withUnencryptedCookie(RememberTokens::COOKIE, $value);

        $response = $this->get('whoami');

        $response->assertContent('guest');
        expect($response->getCookie(RememberTokens::COOKIE, decrypt: false))->toBeNull();
        $this->assertDatabaseCount('user_security_events', 0);
    })->with(['too short' => [str_repeat('a', 63)], 'upper case' => [str_repeat('A', 64)], 'not hex' => [str_repeat('g', 64)]]);

    it('is refused once, however often the request asks who is signed in', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->travel(config()->integer('keystone.remember.lifetime_seconds'))->seconds();
        comeBack($this, $remember, $device);
        Route::middleware('web')->get('asks-twice', fn () => [auth()->check(), auth()->user(), auth()->id()]);
        $rejections = 0;
        Event::listen(SecurityEventRecorded::class, function (SecurityEventRecorded $recorded) use (&$rejections) {
            $rejections += (int) ($recorded->event->type === SecurityEventType::REQUEST_REJECTED);
        });

        $this->get('asks-twice');

        expect($rejections)->toBe(1);
    });

    it('is refused when recording why fails, rather than asking again who is signed in', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->travel(config()->integer('keystone.remember.lifetime_seconds'))->seconds();
        comeBack($this, $remember, $device);
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));

        $response = $this->get('whoami');

        $response->assertContent('guest');
        $this->assertDatabaseCount('user_remember_tokens', 0);
    });
});

describe('a return that owes enrollment', function () {
    it('is refused, loses its token and its cookie, and is told to sign in again', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        config(['keystone.require_second_factor' => true]);
        comeBack($this, $remember, $device);

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login')
            ->assertCookieExpired(RememberTokens::COOKIE)
            ->assertHeaderMissing('Clear-Site-Data');
        $this->assertGuest();
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect(recordedEvents(SecurityEventType::REQUEST_REJECTED)->sole()->only(['user_id', 'reason']))->toEqual([
            'user_id' => $account->getKey(),
            'reason' => 'keystone.enrollment_owed',
        ])->and(session('keystone.status'))->toBe('enrollment-owed');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'session.ended']);
    });

    it('answers a JSON request with 401 and the reason a demoted session gets', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        config(['keystone.require_second_factor' => true]);
        comeBack($this, $remember, $device);

        $response = $this->withCredentials()->getJson('dashboard');

        $response->assertUnauthorized()->assertExactJson(['message' => __('keystone::messages.status.enrollment-owed'), 'reason' => 'demoted']);
    });

    it('says why on the sign-in page, where the next sign-in is held for the enrollment', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        config(['keystone.require_second_factor' => true]);
        comeBack($this, $remember, $device);
        $this->get('dashboard');
        $this->fromRememberCookie(null);

        $page = $this->get(route('login'));
        $held = signInTicked($this);

        $page->assertJsonPath('status', __('keystone::messages.status.enrollment-owed'));
        $held->assertRedirectToRoute('login.enrollment');
    });
});

describe('a session that outlives its absolute lifetime', function () {
    beforeEach(function () {
        config(['keystone.session.absolute_lifetime_seconds' => 3600]);
        $this->freezeSecond();
    });

    it('is restored silently by a live cookie, on a new session id that keeps its data', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        session()->put('app-data', 'kept');
        $sessionId = session()->getId();
        $this->travel(3600)->seconds();

        $response = $this->get('dashboard');

        $response->assertOk()->assertHeaderMissing('Clear-Site-Data');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'session.ended']);
        $this->assertDatabaseCount('user_remember_tokens', 1);
        expect(session()->getId())->not->toBe($sessionId)
            ->and(session('app-data'))->toBe('kept')
            ->and(session('keystone.status'))->toBeNull()
            ->and(session('keystone_signed_in_at_web'))->toBe(now()->getTimestamp())
            ->and(recordedEvents(SecurityEventType::SIGNED_IN)->last()->reason)->toBe('remembered');
    });

    it('expires as ever when its account newly owes enrollment, and the cookie is dropped with its token', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        config(['keystone.require_second_factor' => true]);
        $this->travel(3600)->seconds();

        $response = $this->withCredentials()->getJson('dashboard');

        $response->assertUnauthorized()
            ->assertJsonPath('reason', 'expired')
            ->assertCookieExpired(RememberTokens::COOKIE);
        $this->assertGuest();
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect(session('keystone.status'))->toBe('session-expired')
            ->and(recordedEvents(SecurityEventType::SESSION_ENDED)->sole()->reason)->toBe('expired')
            ->and(recordedEvents(SecurityEventType::REQUEST_REJECTED)->sole()->reason)->toBe('keystone.enrollment_owed');
    });

    it('gets an absolute lifetime of its own once restored', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(3600)->seconds();
        $this->get('dashboard');
        $this->travel(3599)->seconds();

        $response = $this->get('dashboard');

        $response->assertOk();
        expect(recordedEvents(SecurityEventType::SIGNED_IN))->toHaveCount(2);
    });

    it('expires as ever when its cookie is dead, which is dropped with its token', function () {
        config(['keystone.remember.lifetime_seconds' => 1800]);
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(3600)->seconds();

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login')
            ->assertHeader('Clear-Site-Data', '"cache", "storage"')
            ->assertCookieExpired(RememberTokens::COOKIE);
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $account->getKey(), 'reason' => 'expired']);
        $this->assertDatabaseCount('user_remember_tokens', 0);
        expect(session('keystone.status'))->toBe('session-expired');
    });

    it('expires once when its cookie is dead and recording fails', function () {
        config(['keystone.remember.lifetime_seconds' => 1800]);
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(3600)->seconds();
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login');
        expect(recordedEvents(SecurityEventType::SESSION_ENDED))->toHaveCount(1)
            ->and(recordedEvents(SecurityEventType::REQUEST_REJECTED))->toHaveCount(1);
    });

    it('expires as ever in a browser that holds no cookie, leaving the token it was issued', function () {
        $account = $this->createFirstFactorAccount();
        tickedBrowser($this);
        $this->travel(3600)->seconds();

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login')->assertHeader('Clear-Site-Data', '"cache", "storage"');
        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $account->getKey(), 'reason' => 'expired']);
        $this->assertDatabaseCount('user_remember_tokens', 1);
        expect(session('keystone.status'))->toBe('session-expired');
    });
});

describe('a return and the known device', function () {
    it('alerts the owner when a copied cookie returns in a browser without the device cookie', function () {
        $account = $this->createFirstFactorAccount();
        [$remember] = tickedBrowser($this);
        comeBack($this, $remember);

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
        expect(recordedEvents(SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, false])
            ->and(signInAlerts())->toBe(2)
            ->and($this->deviceCookieOf($response))->toBeNull();
        $this->assertDatabaseCount('user_known_devices', 1);
    });

    it('restores every one of several returns from one browser, alerting nobody and leaving the device cookie alone', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $devices = DB::table('user_known_devices')->get();

        $responses = collect(range(1, 3))->map(function () use ($remember, $device) {
            comeBack($this, $remember, $device);

            return $this->get('whoami');
        });

        $responses->each(fn ($response) => $response->assertContent((string) $account->getKey()));
        expect($responses->map(fn ($response) => $this->deviceCookieOf($response))->all())->toBe([null, null, null])
            ->and(recordedEvents(SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false, true, true, true])
            ->and(signInAlerts())->toBe(1)
            ->and(DB::table('user_known_devices')->get())->toEqual($devices);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'device_cookie.reused']);
    });
});

describe('the user\'s own move of the credential epoch', function () {
    beforeEach(function () {
        $this->freezeSecond();
        config(['keystone.remember.lifetime_seconds' => 600]);
    });

    it('hands their browser a new value for the same token, which still ends when it was going to', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $issued = DB::table('user_remember_tokens')->sole();
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(100)->seconds();

        $response = $this->post('end-my-sessions');

        $cookie = $response->getCookie(RememberTokens::COOKIE, decrypt: false);
        $moved = DB::table('user_remember_tokens')->sole();
        expect($cookie->getValue())->toMatch('/^[0-9a-f]{64}$/')->not->toBe($remember)
            ->and($cookie->getExpiresTime())->toBe(now()->addSeconds(500)->getTimestamp())
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax')
            ->and($moved->id)->toBe($issued->id)
            ->and($moved->token_hash)->toBe(hash('sha256', $cookie->getValue()))
            ->and($moved->credential_epoch)->toEqual(1)
            ->and($moved->expires_at)->toBe($issued->expires_at)
            ->and(session('keystone_remember_web'))->toBe($issued->id);
    });

    it('restores from the new value and never from the one it replaced', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        $fresh = $this->rememberCookieOf($this->post('end-my-sessions'));
        comeBack($this, $remember);

        $copy = $this->get('whoami');
        comeBack($this, $fresh);
        $owner = $this->get('whoami');

        $copy->assertContent('guest');
        $owner->assertContent((string) $account->getKey());
    });

    it('ends when the token was going to, however late the epoch moved', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(599)->seconds();
        $fresh = $this->rememberCookieOf($this->post('end-my-sessions'));
        $this->travel(1)->seconds();
        comeBack($this, $fresh);

        $response = $this->get('whoami');

        $response->assertContent('guest');
    });

    it('leaves the tokens of the account\'s other devices dead', function () {
        $this->createFirstFactorAccount();
        [$phone, $phoneDevice] = tickedBrowser($this);
        comeBack($this, null);
        [$laptop, $laptopDevice] = tickedBrowser($this);
        $this->fromRememberCookie($laptop)->fromDevice($laptopDevice)->post('end-my-sessions');
        comeBack($this, $phone, $phoneDevice);

        $response = $this->get('whoami');

        $response->assertContent('guest');
        expect(DB::table('user_remember_tokens')->pluck('credential_epoch')->all())->toEqual([1]);
    });

    it('hands out no cookie when the session\'s token has expired', function () {
        $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $issued = DB::table('user_remember_tokens')->sole();
        $this->fromRememberCookie($remember)->fromDevice($device);
        $this->travel(600)->seconds();

        $response = $this->post('end-my-sessions');

        expect($this->rememberCookieOf($response))->toBeNull()
            ->and(DB::table('user_remember_tokens')->sole())->toEqual($issued);
    });

    it('hands out no cookie to a session that was never remembered', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->post('end-my-sessions');

        expect($this->rememberCookieOf($response))->toBeNull();
        $this->assertDatabaseCount('user_remember_tokens', 0);
    });
});

describe('a session demoted because its account newly owes enrollment', function () {
    it('forgets its token and drops its cookie, so the browser is not refused a second time', function () {
        $account = $this->createFirstFactorAccount();
        [$remember, $device] = tickedBrowser($this);
        $this->fromRememberCookie($remember)->fromDevice($device);
        config(['keystone.require_second_factor' => true]);

        $response = $this->get('dashboard');

        $response->assertRedirectToRoute('login')->assertCookieExpired(RememberTokens::COOKIE);
        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $account->getKey(), 'reason' => 'demoted']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'request.rejected']);
        $this->assertDatabaseCount('user_remember_tokens', 0);
    });

    it('leaves the tokens of the account\'s other devices to be refused when they return', function () {
        $this->createFirstFactorAccount();
        tickedBrowser($this);
        comeBack($this, null);
        [$laptop, $laptopDevice] = tickedBrowser($this);
        $this->fromRememberCookie($laptop)->fromDevice($laptopDevice);
        config(['keystone.require_second_factor' => true]);

        $this->get('dashboard');

        $this->assertDatabaseCount('user_remember_tokens', 1);
    });
});

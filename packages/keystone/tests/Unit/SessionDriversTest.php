<?php

namespace ClaudioDekker\Keystone\Tests\Unit;

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\EnrollmentCeremonies;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\Tests\Fixtures\DrawingType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

const SESSION_COOKIE = 'keystone_session';

beforeEach(function () {
    config([
        'auth.guards.web.driver' => 'keystone',
        'auth.providers.users.model' => User::class,
    ]);

    $this->sessionPath = storage_path('framework/keystone-test-sessions');
    File::ensureDirectoryExists($this->sessionPath);
});

afterEach(function () {
    File::deleteDirectory($this->sessionPath);
});

/**
 * Handle one request from a browser holding the given cookies.
 *
 * @param  array<string, string>  $browser
 */
function visit(string $driver, array &$browser, Closure $callback): mixed
{
    $request = Request::create('/', cookies: $browser);

    $handler = match ($driver) {
        'file' => new FileSessionHandler(app('files'), test()->sessionPath, 120),
        'redis' => new CacheBasedSessionHandler(Cache::store('redis'), 120),
        'cookie' => tap(new CookieSessionHandler(app('cookie'), 120, false))->setRequest($request),
        'database' => new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app()),
    };

    $session = new Store(SESSION_COOKIE, $handler, $browser[SESSION_COOKIE] ?? null);
    $session->start();
    app()->instance('session.store', $session);
    Auth::forgetGuards();

    /** @var KeystoneGuard $guard */
    $guard = Auth::guard('web');
    $result = $callback($guard);

    $session->save();
    $browser[SESSION_COOKIE] = $session->getId();

    foreach (app('cookie')->getQueuedCookies() as $cookie) {
        $browser[$cookie->getName()] = (string) $cookie->getValue();
    }

    app('cookie')->flushQueuedCookies();

    return $result;
}

it('ends the account\'s other sessions when its epoch moves', function (string $driver) {
    $user = User::factory()->create();
    $laptop = [];
    $phone = [];
    visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->signIn($user));
    visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->signIn($user));
    $replayed = $laptop;

    expect(visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->user()))->not->toBeNull();

    DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

    expect(visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull()
        ->and(visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull()
        ->and(visit($driver, $replayed, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull();
})->with(['file', 'redis', 'cookie', 'database']);

it('ends every other session of the account when a change ends them, keeping the mover\'s', function (string $driver) {
    $user = User::factory()->create();
    $laptop = [];
    $phone = [];
    visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->signIn($user));
    visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->signIn($user));

    visit($driver, $laptop, fn (KeystoneGuard $guard) => (new AccountChanges($guard))->change($user, fn (AccountChange $change) => $change->endSessions()));

    expect(visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->user()))->not->toBeNull()
        ->and(visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull();
})->with(['file', 'redis', 'cookie', 'database']);

it('ends every account\'s sessions', function (string $driver) {
    $jane = User::factory()->create();
    $john = User::factory()->create();
    $laptop = [];
    $phone = [];
    visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->signIn($jane));
    visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->signIn($john));

    (new AccountChanges(Auth::guard('web')))->endEverySession();

    expect(visit($driver, $laptop, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull()
        ->and(visit($driver, $phone, fn (KeystoneGuard $guard) => $guard->user()))->toBeNull();
})->with(['file', 'redis', 'cookie', 'database']);

it('keeps a running enrollment ceremony across requests, in a session small enough for a cookie, whatever its type draws for the form', function (string $driver) {
    $user = User::factory()->create();
    $type = new DrawingType;
    $browser = [];
    visit($driver, $browser, fn (KeystoneGuard $guard) => $guard->signIn($user));

    $started = visit($driver, $browser, fn (KeystoneGuard $guard) => (new EnrollmentCeremonies($guard))->resolve($type, $user));
    $stored = strlen(serialize(session()->all()));
    $running = visit($driver, $browser, fn (KeystoneGuard $guard) => (new EnrollmentCeremonies($guard))->running($type));

    expect(strlen($started->page['drawing']))->toBe(16384)
        ->and($running)->toEqual($started)
        ->and($stored)->toBeLessThan(2048);
})->with(['file', 'redis', 'cookie', 'database']);

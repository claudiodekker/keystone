<?php

namespace ClaudioDekker\Keystone\Tests\Unit;

use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\Tests\Fixtures\Member;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithArchivedAt;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithEagerLoads;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithoutScopes;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithUuidIdentifier;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Recaller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as LaravelUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

beforeEach(function () {
    config([
        'auth.guards.web.driver' => 'keystone',
        'auth.providers.users.model' => User::class,
    ]);
});

function nextRequest(): KeystoneGuard
{
    Auth::forgetGuards();

    return Auth::guard('web');
}

test('the keystone driver builds a keystone guard', function () {
    expect(Auth::guard('web'))->toBeInstanceOf(KeystoneGuard::class);
});

it('refuses a user model that does not implement KeystoneUser', function () {
    config(['auth.providers.users.model' => LaravelUser::class]);

    Auth::guard('web');
})->throws(\LogicException::class, 'auth.providers.users.model must implement ClaudioDekker\Keystone\KeystoneUser');

it('refuses a provider that is not eloquent', function () {
    config(['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]);

    Auth::guard('web');
})->throws(\LogicException::class, 'auth.providers.users must use the eloquent driver');

it('resolves a signed-in user on a later request', function () {
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);

    expect(nextRequest()->user())->toBeInstanceOf(User::class)
        ->getKey()->toBe($user->getKey());
});

it('rotates the session id when signing in', function () {
    $session = app('session.store');
    $before = $session->getId();

    Auth::guard('web')->signIn(User::factory()->create());

    expect($session->getId())->not->toBe($before);
});

it('refuses to sign in an account that no longer exists', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->delete();

    Auth::guard('web')->signIn($user);
})->throws(\LogicException::class, 'The account being signed in no longer exists.');

it('refuses to sign in a disabled or suspended account', function (string $column) {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->getKey())->update([$column => now()]);

    Auth::guard('web')->signIn($user);
})->with(['deleted_at', 'invalidated_at', 'suspended_at'])->throws(\LogicException::class, 'The account being signed in is disabled or suspended.');

it('fires the login event when signing in', function () {
    Event::fake([Login::class]);
    $user = User::factory()->create();

    Auth::guard('web')->signIn($user);

    Event::assertDispatched(Login::class, fn (Login $event) => $event->user->is($user) && ! $event->remember);
});

it('keeps the model\'s eager loads', function () {
    config(['auth.providers.users.model' => UserWithEagerLoads::class]);
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);

    expect(nextRequest()->user()->relationLoaded('self'))->toBeTrue();
});

it('finds the account by its auth identifier', function () {
    Schema::table('users', fn (Blueprint $table) => $table->string('uuid')->nullable());
    config(['auth.providers.users.model' => UserWithUuidIdentifier::class]);
    User::factory()->create(['uuid' => 'other']);
    $user = UserWithUuidIdentifier::query()->findOrFail(User::factory()->create(['uuid' => '1'])->getKey());
    Auth::guard('web')->signIn($user);

    expect(nextRequest()->user()?->getKey())->toBe($user->getKey());
});

it('ends the session of an account soft deleted under its own column name', function () {
    Schema::table('users', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
    config(['auth.providers.users.model' => UserWithArchivedAt::class]);
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);

    DB::table('users')->where('id', $user->getKey())->update(['archived_at' => now()]);

    expect(nextRequest()->user())->toBeNull();
});

it('leaves the remember token alone when signing out', function () {
    $user = User::factory()->create(['remember_token' => 'legacy-token']);
    $guard = Auth::guard('web');
    $guard->signIn($user);

    $guard->logout();

    expect($user->fresh()->remember_token)->toBe('legacy-token');
});

it('reads the account state in one query, ignoring the cache', function () {
    Auth::guard('web')->signIn(User::factory()->create());
    $guard = nextRequest();

    DB::enableQueryLog();
    $guard->user();
    $guard->user();

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('ends a session stamped with an older credential epoch', function () {
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);
    $session = app('session.store');
    $id = $session->getId();

    DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

    expect(nextRequest()->user())->toBeNull()
        ->and($session->getId())->not->toBe($id)
        ->and($session->all())->not->toHaveKey(Auth::guard('web')->getName());
});

it('ends the session of a disabled or suspended account, even when the model drops its scopes', function (string $column) {
    config(['auth.providers.users.model' => UserWithoutScopes::class]);
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);
    $session = app('session.store');
    $id = $session->getId();

    DB::table('users')->where('id', $user->getKey())->update([$column => now()]);

    expect(nextRequest()->user())->toBeNull()
        ->and($session->getId())->not->toBe($id);
})->with(['deleted_at', 'invalidated_at', 'suspended_at']);

it('ends the session of an account that no longer exists', function () {
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);

    DB::table('users')->where('id', $user->getKey())->delete();

    expect(nextRequest()->user())->toBeNull();
});

it('ends a session that was never stamped with an epoch', function () {
    $user = User::factory()->create();
    app('session.store')->put(Auth::guard('web')->getName(), $user->getKey());

    expect(nextRequest()->user())->toBeNull();
});

it('ignores the Laravel remember-me cookie', function () {
    $user = User::factory()->create(['remember_token' => 'legacy-token', 'password' => Hash::make('secret')]);
    $guard = Auth::guard('web');
    $recaller = new Recaller($user->getKey().'|legacy-token|'.$guard->hashPasswordForCookie($user->password));
    $guard->setRequest(Request::create('/', cookies: [$guard->getRecallerName() => $recaller->id().'|'.$recaller->token().'|'.$recaller->hash()]));

    expect($guard->user())->toBeNull();
});

it('signs nobody in through the SessionGuard credential and session methods', function (Closure $call) {
    $user = User::factory()->create(['email' => 'taylor@example.com', 'password' => $hash = Hash::make('secret')]);
    $guard = Auth::guard('web');
    $guard->setRequest(Request::create('/', server: ['PHP_AUTH_USER' => 'taylor@example.com', 'PHP_AUTH_PW' => 'secret']));

    try {
        $call($guard, $user);
    } catch (UnauthorizedHttpException) {
        //
    }

    expect($guard->user())->toBeNull()
        ->and(nextRequest()->user())->toBeNull()
        ->and($user->fresh()->password)->toBe($hash);
})->with([
    'attempt' => fn (KeystoneGuard $guard) => expect($guard->attempt(['email' => 'taylor@example.com', 'password' => 'secret']))->toBeFalse(),
    'attemptWhen' => fn (KeystoneGuard $guard) => expect($guard->attemptWhen(['email' => 'taylor@example.com', 'password' => 'secret']))->toBeFalse(),
    'validate' => fn (KeystoneGuard $guard) => expect($guard->validate(['email' => 'taylor@example.com', 'password' => 'secret']))->toBeFalse(),
    'once' => fn (KeystoneGuard $guard) => expect($guard->once(['email' => 'taylor@example.com', 'password' => 'secret']))->toBeFalse(),
    'basic' => fn (KeystoneGuard $guard) => $guard->basic(),
    'onceBasic' => fn (KeystoneGuard $guard) => $guard->onceBasic(),
    'login' => fn (KeystoneGuard $guard, User $user) => $guard->login($user, remember: true),
    'onceUsingId' => fn (KeystoneGuard $guard, User $user) => expect($guard->onceUsingId($user->getKey()))->toBeFalse(),
    'loginUsingId' => fn (KeystoneGuard $guard, User $user) => expect($guard->loginUsingId($user->getKey()))->toBeFalse(),
]);

it('lets tests act as a user', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Route::middleware(['web', 'auth'])->get('/me', fn () => Auth::id());
    $user = User::factory()->create();

    $this->actingAs($user)->get('/me')->assertSee((string) $user->getKey());
});

it('leaves the password alone on logoutOtherDevices', function () {
    $user = User::factory()->create(['password' => $hash = Hash::make('secret', ['rounds' => 5])]);
    $guard = Auth::guard('web');
    $guard->signIn($user);

    expect($guard->logoutOtherDevices('secret'))->toBeNull()
        ->and($user->fresh()->password)->toBe($hash);
});

it('refuses HTTP basic credentials', function (string $method) {
    User::factory()->create(['email' => 'taylor@example.com', 'password' => Hash::make('secret')]);
    $guard = Auth::guard('web');
    $guard->setRequest(Request::create('/', server: ['PHP_AUTH_USER' => 'taylor@example.com', 'PHP_AUTH_PW' => 'secret']));

    $guard->{$method}();
})->with(['basic', 'onceBasic'])->throws(UnauthorizedHttpException::class);

it('works with a user model that has its own table and key name', function () {
    Schema::create('members', function (Blueprint $table) {
        $table->id('member_id');
        $table->unsignedBigInteger('credential_epoch')->default(0);
        $table->timestamp('invalidated_at')->nullable();
        $table->timestamp('suspended_at')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    config(['auth.providers.users.model' => Member::class]);
    $member = Member::create();
    Auth::guard('web')->signIn($member);

    expect(nextRequest()->user()?->getKey())->toBe($member->getKey());

    DB::table('members')->where('member_id', $member->getKey())->increment('credential_epoch');

    expect(nextRequest()->user())->toBeNull();
});

it('stamps the credential epoch the account was read with, not the current one', function () {
    $user = User::factory()->create();
    $read = User::query()->findOrFail($user->getKey());

    DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');
    Auth::guard('web')->signIn($read);

    expect(nextRequest()->user())->toBeNull();
});

it('stamps the sign-in time', function () {
    $this->freezeSecond();

    Auth::guard('web')->signIn(User::factory()->create());

    expect(Auth::guard('web')->signedInAt()?->getTimestamp())->toBe(now()->getTimestamp());
});

it('has no sign-in time while nobody signed in', function () {
    expect(Auth::guard('web')->signedInAt())->toBeNull();
});

it('ends the session and regenerates the CSRF token when signing out', function () {
    Event::fake([Logout::class]);
    $user = User::factory()->create();
    $guard = Auth::guard('web');
    $guard->signIn($user);
    $session = app('session.store');
    $session->put('app-data', 'kept until sign-out');
    [$id, $token] = [$session->getId(), $session->token()];

    $guard->signOut();

    expect($guard->user())->toBeNull()
        ->and(nextRequest()->user())->toBeNull()
        ->and($session->getId())->not->toBe($id)
        ->and($session->token())->not->toBe($token)
        ->and($session->has('app-data'))->toBeFalse();
    Event::assertDispatched(Logout::class, fn (Logout $event) => $event->user->is($user));
});

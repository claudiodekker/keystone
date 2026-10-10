<?php

namespace ClaudioDekker\Keystone\Tests\Unit;

use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\Registering;
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
    'login' => fn (KeystoneGuard $guard, User $user) => expect(fn () => $guard->login($user, remember: true))->toThrow(\LogicException::class, "Auth::login() can't sign anyone in"),
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
    // Keystone's migrations reference users, so the member's devices need a row there.
    User::factory()->create(['id' => $member->getKey()]);
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

describe('the absolute lifetime', function () {
    beforeEach(function () {
        config(['keystone.session.absolute_lifetime_seconds' => 3600]);
    });

    it('keeps a session until its lifetime from the sign-in has passed', function () {
        $this->freezeSecond();
        $user = User::factory()->create();
        Auth::guard('web')->signIn($user);

        $this->travel(3599)->seconds();

        expect(nextRequest()->user()?->getKey())->toBe($user->getKey());
    });

    it('ends a session once its lifetime from the sign-in has passed, however active it was', function () {
        $this->freezeSecond();
        Auth::guard('web')->signIn(User::factory()->create());
        $this->travel(3599)->seconds();
        nextRequest()->user();

        $this->travel(1)->seconds();

        expect(nextRequest()->user())->toBeNull();
    });

    it('ends an expired session, clearing the site\'s data and flashing why', function () {
        $this->freezeSecond();
        Auth::guard('web')->signIn(User::factory()->create());
        $session = app('session.store');
        $id = $session->getId();

        $this->travel(3600)->seconds();

        expect(nextRequest()->user())->toBeNull()
            ->and($session->getId())->not->toBe($id)
            ->and($session->all())->not->toHaveKey(Auth::guard('web')->getName())
            ->and($session->get('keystone.status'))->toBe('session-expired')
            ->and(request()->attributes->get(KeystoneGuard::ENDED_SESSION))->toBeTrue()
            ->and(request()->attributes->get(KeystoneGuard::EXPIRED_SESSION))->toBeTrue();
    });

    it('records the expiry on the account\'s trail', function () {
        $this->freezeSecond();
        $user = User::factory()->create();
        Auth::guard('web')->signIn($user);

        $this->travel(3600)->seconds();
        nextRequest()->user();

        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $user->getKey(), 'reason' => 'expired']);
    });

    it('ends a session whose sign-in time is missing or in the future', function (mixed $signedInAt) {
        $this->freezeSecond();
        $user = User::factory()->create();
        Auth::guard('web')->signIn($user);
        app('session.store')->put('keystone_signed_in_at_web', $signedInAt instanceof Closure ? $signedInAt() : $signedInAt);

        expect(nextRequest()->user())->toBeNull();
        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $user->getKey(), 'reason' => 'expired']);
    })->with([
        'missing' => [null],
        'not a timestamp' => ['yesterday'],
        'a second ahead' => [fn () => now()->addSecond()->getTimestamp()],
    ]);

    it('never ends a session by its age when the lifetime is off', function () {
        config(['keystone.session.absolute_lifetime_seconds' => null]);
        $user = User::factory()->create();
        Auth::guard('web')->signIn($user);
        app('session.store')->forget('keystone_signed_in_at_web');

        $this->travel(10)->years();

        expect(nextRequest()->user()?->getKey())->toBe($user->getKey());
    });

    it('ends a session on an older epoch without calling it expired', function () {
        $this->freezeSecond();
        $user = User::factory()->create();
        Auth::guard('web')->signIn($user);
        DB::table('users')->where('id', $user->getKey())->increment('credential_epoch');

        $this->travel(3600)->seconds();

        expect(nextRequest()->user())->toBeNull()
            ->and(app('session.store')->has('keystone.status'))->toBeFalse();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'session.ended']);
    });
});

it('never brings sudo with a sign-in when no request context was captured', function () {
    Auth::guard('web')->signIn(User::factory()->create());

    expect(Auth::guard('web')->sudoGrant())->toBeNull();
});

describe('a registration', function () {
    it('keeps the proven address until its window ends', function () {
        $this->freezeSecond();
        Auth::guard('web')->startRegistration('new@example.com');

        $this->travel(Registering::WINDOW_SECONDS - 1)->seconds();
        expect(nextRequest()->registration())->address->toBe('new@example.com')
            ->endsAt()->toEqual(now()->addSecond()->toImmutable());

        $this->travel(1)->second();
        expect(nextRequest()->registration())->toBeNull()
            ->and(session()->has('keystone_phase_web'))->toBeFalse();
    });

    it('ignores a proven address with no believable time', function (mixed $held) {
        session()->put('keystone_phase_web', $held);

        expect(Auth::guard('web')->registration())->toBeNull();
    })->with([
        'a future time' => fn () => ['phase' => 'registering', 'address' => 'new@example.com', 'started_at' => now()->addMinute()->getTimestamp(), 'verified' => true],
        'no time' => [['phase' => 'registering', 'address' => 'new@example.com', 'verified' => true]],
        'no address' => fn () => ['phase' => 'registering', 'started_at' => now()->getTimestamp(), 'verified' => true],
        'no verified flag' => fn () => ['phase' => 'registering', 'address' => 'new@example.com', 'started_at' => now()->getTimestamp()],
        'a verified flag that isn\'t a boolean' => fn () => ['phase' => 'registering', 'address' => 'new@example.com', 'started_at' => now()->getTimestamp(), 'verified' => 1],
        'not an array' => ['new@example.com'],
    ]);

    it('holds whether the address was proven', function () {
        $guard = Auth::guard('web');

        $guard->startRegistration('new@example.com');
        expect($guard->registration())->verified->toBeTrue();

        $guard->startUnverifiedRegistration('new@example.com');
        expect($guard->registration())->verified->toBeFalse();
    });

    it('starts on a new session id, dropping sudo, every ceremony slot and the pending sign-in', function (Closure $start, Closure $hold) {
        $guard = Auth::guard('web');
        $hold($guard);
        $guard->slots()->put('form', 'challenge', 'bytes', capSeconds: 300);
        $before = session()->getId();

        $start($guard);

        expect(session()->getId())->not->toBe($before)
            ->and($guard->pending())->toBeNull()
            ->and($guard->sudoInProgress())->toBeNull()
            ->and($guard->slots()->get('form', 'challenge'))->toBeNull()
            ->and($guard->registration()?->address)->toBe('new@example.com');
    })->with([
        'a spent link' => [fn (KeystoneGuard $guard) => $guard->startRegistration('new@example.com')],
        'a typed address' => [fn (KeystoneGuard $guard) => $guard->startUnverifiedRegistration('new@example.com')],
    ])->with([
        'a pending sign-in' => [fn (KeystoneGuard $guard) => $guard->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/')],
        'a sudo-in-progress' => [fn (KeystoneGuard $guard) => $guard->beginSudo('/settings')],
    ]);

    it('ends with every change of auth level', function (Closure $change) {
        $guard = Auth::guard('web');
        $guard->startRegistration('new@example.com');

        $change($guard, User::factory()->create());

        expect($guard->registration())->toBeNull();
    })->with([
        'a sign-in' => [fn (KeystoneGuard $guard, User $account) => $guard->signIn($account)],
        'a hold' => [fn (KeystoneGuard $guard, User $account) => $guard->hold($account, 'form', PendingStage::ENROLLMENT, '/')],
    ]);

    it('ends without an account, closing every ceremony slot', function () {
        $guard = Auth::guard('web');
        $guard->startRegistration('new@example.com');
        $guard->slots()->put('form', 'registration', 'bytes', capSeconds: 300);

        $guard->endRegistration();

        expect($guard->registration())->toBeNull()
            ->and($guard->slots()->get('form', 'registration'))->toBeNull();
    });
});

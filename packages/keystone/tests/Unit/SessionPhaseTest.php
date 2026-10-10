<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\Tests\Fixtures\User;

beforeEach(function () {
    $this->freezeSecond();
    app()->instance(RequestContext::class, new RequestContext(ipAddress: '127.0.0.1'));
});

it('holds only the registration once one starts after a pending sign-in', function () {
    $guard = Keystone::guard();
    $guard->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/');

    $guard->startRegistration('new@example.com');

    expect($guard->isPendingAt(PendingStage::CHALLENGE))->toBeFalse()
        ->and($guard->pending())->toBeNull()
        ->and($guard->registration()?->address)->toBe('new@example.com');
});

it('holds only the pending sign-in once one is held during a registration', function () {
    $guard = Keystone::guard();
    $guard->startRegistration('new@example.com');

    $guard->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/');

    expect($guard->registration())->toBeNull()
        ->and($guard->pending())->not->toBeNull();
});

it('holds only the sudo grant once a pending sign-in signs in', function () {
    $guard = Keystone::guard();
    $user = User::factory()->create();
    $guard->hold($user, 'form', PendingStage::CHALLENGE, '/');

    $guard->signIn($user);

    expect($guard->isPendingAt(PendingStage::CHALLENGE))->toBeFalse()
        ->and($guard->pending())->toBeNull()
        ->and($guard->sudoGrant())->not->toBeNull();
});

it('replaces a sudo grant with the sudo-in-progress that begins', function () {
    $guard = Keystone::guard();
    $guard->signIn(User::factory()->create());

    $guard->beginSudo('/settings');

    expect($guard->sudoGrant())->toBeNull()
        ->and($guard->sudoInProgress()?->intendedUrl)->toBe('/settings');
});

it('leaves no record behind a change of auth level that writes none', function (Closure $arrange, Closure $change) {
    $guard = Keystone::guard();
    $arrange($guard);

    $change($guard);

    expect(session()->has('keystone_phase_web'))->toBeFalse();
})->with([
    'forgetting a pending sign-in' => [
        fn (KeystoneGuard $guard) => $guard->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/'),
        fn (KeystoneGuard $guard) => $guard->forgetPending(),
    ],
    'ending a sudo grant' => [
        fn (KeystoneGuard $guard) => $guard->signIn(User::factory()->create()),
        fn (KeystoneGuard $guard) => $guard->endSudo(),
    ],
    'ending a sudo-in-progress' => [
        function (KeystoneGuard $guard) {
            $guard->signIn(User::factory()->create());
            $guard->beginSudo('/settings');
        },
        fn (KeystoneGuard $guard) => $guard->endSudo(),
    ],
    'ending a sudo grant on a network change' => [
        fn (KeystoneGuard $guard) => $guard->signIn(User::factory()->create()),
        fn (KeystoneGuard $guard) => $guard->endSudoOnNetworkChange(),
    ],
    'ending a registration' => [
        fn (KeystoneGuard $guard) => $guard->startRegistration('new@example.com'),
        fn (KeystoneGuard $guard) => $guard->endRegistration(),
    ],
]);

it('reads a record it can\'t believe as none and forgets it', function (Closure $held, Closure $read) {
    session()->put('keystone_phase_web', $held());

    $record = $read();

    expect($record)->toBeNull()
        ->and(session()->has('keystone_phase_web'))->toBeFalse();
})->with([
    'a pending sign-in with no account' => [fn () => ['phase' => 'pending', 'first_factor' => 'form', 'origin' => 'login', 'stage' => 'challenge', 'intended_url' => '/', 'held_at' => now()->getTimestamp(), 'epoch' => 0, 'second_factor_passed' => false, 'pending_challenge_id' => null, 'remember_me' => 'not-asked'], fn () => Keystone::guard()->pending()],
    'a pending sign-in held in the future' => [fn () => ['phase' => 'pending', 'account' => 1, 'first_factor' => 'form', 'origin' => 'login', 'stage' => 'challenge', 'intended_url' => '/', 'held_at' => now()->addMinute()->getTimestamp(), 'epoch' => 0, 'second_factor_passed' => false, 'pending_challenge_id' => null, 'remember_me' => 'not-asked'], fn () => Keystone::guard()->pending()],
    'a registration with no address' => [fn () => ['phase' => 'registering', 'started_at' => now()->getTimestamp(), 'verified' => true], fn () => Keystone::guard()->registration()],
    'a registration started in the future' => [fn () => ['phase' => 'registering', 'address' => 'new@example.com', 'started_at' => now()->addMinute()->getTimestamp(), 'verified' => true], fn () => Keystone::guard()->registration()],
    'a sudo-in-progress with no intended URL' => [fn () => ['phase' => 'sudo_in_progress', 'started_at' => now()->getTimestamp(), 'first_factor' => null], fn () => Keystone::guard()->sudoInProgress()],
    'a sudo-in-progress started in the future' => [fn () => ['phase' => 'sudo_in_progress', 'started_at' => now()->addMinute()->getTimestamp(), 'intended_url' => '/', 'first_factor' => null], fn () => Keystone::guard()->sudoInProgress()],
    'a sudo grant with no subnet' => [fn () => ['phase' => 'sudo_granted', 'granted_at' => now()->getTimestamp()], fn () => Keystone::guard()->sudoGrant()],
    'a sudo grant made in the future' => [fn () => ['phase' => 'sudo_granted', 'granted_at' => now()->addMinute()->getTimestamp(), 'subnet' => '127.0.0.0/24'], fn () => Keystone::guard()->sudoGrant()],
    'an unknown phase' => [fn () => ['phase' => 'elsewhere', 'granted_at' => now()->getTimestamp(), 'subnet' => '127.0.0.0/24'], fn () => Keystone::guard()->sudoGrant()],
    'no phase' => [fn () => ['granted_at' => now()->getTimestamp(), 'subnet' => '127.0.0.0/24'], fn () => Keystone::guard()->sudoGrant()],
]);

it('leaves a pending sign-in that ran out for the pending sign-in to drop on a new session id', function () {
    Keystone::guard()->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/');
    $this->travel(PendingSignIn::LIFETIME_SECONDS)->seconds();

    expect(Keystone::guard()->registration())->toBeNull()
        ->and(Keystone::guard()->sudoGrant())->toBeNull()
        ->and(Keystone::guard()->isPendingAt(PendingStage::CHALLENGE))->toBeTrue();

    $sessionId = session()->getId();

    expect(Keystone::guard()->pending())->toBeNull()
        ->and(Keystone::guard()->isPendingAt(PendingStage::CHALLENGE))->toBeFalse()
        ->and(session()->getId())->not->toBe($sessionId);
});

<?php

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SudoAttempt;
use ClaudioDekker\Keystone\SudoResult;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['cache.limiter' => 'array', 'keystone.require_second_factor' => false, 'keystone.require_recovery_codes' => false]);
    app()->forgetInstance(CacheRateLimiter::class);
    app()->instance(RequestContext::class, RequestContext::capture(request()));
});

it('never grants an account the sign-in decision refuses, even one the guard still names', function (Closure $bar) {
    $user = User::factory()->create();
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    (new AccountChanges(Keystone::guard()))->change($user, fn (AccountChange $change) => $change->addCredential($type, identifier: null, secret: FormType::hash('correct horse battery staple')));
    $bar($user);
    Keystone::guard()->setUser(User::query()->withoutGlobalScopes()->findOrFail($user->getKey()));
    Keystone::guard()->beginSudo('/settings');

    $result = (new SudoAttempt(Keystone::guard(), new RateLimiter(request(), app(RequestContext::class), Keystone::guard())))->attempt(Keystone::guard()->sudoInProgress(), $type, ['secret' => 'correct horse battery staple']);

    expect($result)->toBe(SudoResult::REFUSED)
        ->and(Keystone::guard()->sudoGrant())->toBeNull()
        ->and(Keystone::guard()->sudoInProgress())->not->toBeNull();
    $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'user_id' => $user->getKey(), 'flow' => 'sudo', 'reason' => 'keystone.barred']);
    $this->assertDatabaseMissing('user_security_events', ['type' => 'sudo.granted', 'flow' => 'sudo']);
})->with([
    'a suspended account' => [fn (User $user) => DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()])],
    'an account that newly owes enrollment' => [fn (User $user) => config(['keystone.require_second_factor' => true])],
]);

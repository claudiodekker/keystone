<?php

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Jobs\EndAllSessions;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

dataset('ending the account\'s sessions', [
    'with the command' => [fn (KeystoneUser $user) => Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret'])],
    'through the job an admin panel dispatches' => [fn (KeystoneUser $user) => EndSessions::dispatchSync($user, operator: 'margaret')],
]);

dataset('ending every account\'s sessions', [
    'with the command' => [fn () => Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret'])],
    'through the job an admin panel dispatches' => [fn () => EndAllSessions::dispatchSync(operator: 'margaret')],
]);

it("signs out a session that signed in before the operator ended the account's sessions on its next request, and tells the user why", function (Closure $endSessions) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $endSessions($user);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
})->with('ending the account\'s sessions');

it("keeps another account's session signed in", function (Closure $endSessions) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $other = $this->createUser();
    $this->addVerifiedAddress($other, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user)->arrangeCredential(Surface::SIGN_IN, $other);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $other),
    ]);

    $gracesBrowser = $this->switchToFreshBrowser();
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $endSessions($user);

    $this->switchBackTo($gracesBrowser);
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
})->with('ending the account\'s sessions');

it("signs out every account's sessions", function (Closure $endAllSessions) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $other = $this->createUser();
    $this->addVerifiedAddress($other, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user)->arrangeCredential(Surface::SIGN_IN, $other);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $other),
    ]);

    $gracesBrowser = $this->switchToFreshBrowser();
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $endAllSessions();

    Auth::forgetGuards();
    $inAdasBrowser = $this->get(route('login'));

    $this->switchBackTo($gracesBrowser);
    $inGracesBrowser = $this->get(route('login'));

    $this->assertSignInPage($inAdasBrowser, ['status' => __('keystone::messages.session-ended')]);
    $this->assertSignInPage($inGracesBrowser, ['status' => __('keystone::messages.session-ended')]);
})->with('ending every account\'s sessions');

it('records sessions.terminated for the account, by the operator who ended them', function () {
    $user = $this->createUser();

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SESSIONS_TERMINATED])
        ->and($events[0]->actor)->toBe(Actor::OPERATOR)
        ->and($events[0]->operator)->toBe('margaret');
});

it("stores the operator's name cut to 64 characters, with control characters replaced by spaces", function () {
    $user = $this->createUser();

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => "margaret\nINFO: forged ".str_repeat('x', 60)]);

    $event = $this->securityEvents()->for($user)->sole();

    expect($event->operator)->toBe('margaret INFO: forged '.str_repeat('x', 42));
});

it('logs one sessions.terminated about nobody for --all, by the operator who ran it', function () {
    $this->createUser();
    $this->createUser();

    Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret']);

    $logged = $this->securityEvents()->logged();

    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($logged)->toHaveCount(1)
        ->and($logged[0]['type'])->toBe('sessions.terminated')
        ->and($logged[0]['user_id'])->toBeNull()
        ->and($logged[0]['actor'])->toBe('operator')
        ->and($logged[0]['operator'])->toBe('margaret')
        ->and($logged[0]['reason'])->toBe('keystone.every_account');
});

it('logs every --all, however close together they run', function () {
    Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret']);
    Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret']);

    $logged = $this->securityEvents()->logged();

    expect($logged)->toHaveCount(2);
});

it('records session.ended with the reason epoch when the ended session makes its next request', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    Auth::forgetGuards();
    $this->get(route('login'));

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SESSIONS_TERMINATED, SecurityEventType::SESSION_ENDED])
        ->and($events[2]->reason)->toBe('keystone.epoch')
        ->and($events[2]->actor)->toBe(Actor::USER);
});

it("clears the browser's site data on the request that finds the session ended", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
});

it('signs out a session from before Keystone, which carries no epoch, on its first request', function () {
    config(['keystone.session.absolute_lifetime_seconds' => null]);
    $user = $this->createUser();

    $this->withSession([Keystone::guard()->getName() => $user->getAuthIdentifier()]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
});

it("signs out the sessions of an account the app's global scopes hid while the operator ended them", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->beforeApplicationDestroyed(fn () => Model::clearBootedModels());

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Keystone::userModel()::addGlobalScope('hidden', fn (Builder $query) => $query->whereKeyNot($user->getAuthIdentifier()));
    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    Model::clearBootedModels();

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
});

it('ends nothing and records nothing when the transaction the job ran in rolls back', function (Closure $endSessions) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $rolledBack = fn () => DB::transaction(function () use ($endSessions, $user) {
        $endSessions($user);

        throw new RuntimeException('The admin panel failed after ending the sessions.');
    });

    expect($rolledBack)->toThrow(RuntimeException::class);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
    expect(array_column($this->securityEvents()->logged(), 'type'))->toBe(['signed_in']);
})->with([
    'the account' => [fn (KeystoneUser $user) => EndSessions::dispatchSync($user, operator: 'margaret')],
    'every account' => [fn () => EndAllSessions::dispatchSync(operator: 'margaret')],
]);

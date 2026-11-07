<?php

use ClaudioDekker\Keystone\Jobs\EndAllSessions;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\Actor;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;

it("signs out a session that signed in before the operator ended the account's sessions, on its next request", function () {
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

    $this->assertSignInPage($response);
});

it('tells the user on the sign-in page that their session was ended', function () {
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

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-ended')]);
});

it("keeps another account's session signed in", function () {
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

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $this->switchBackTo($gracesBrowser);
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it("signs out every account's sessions with --all", function () {
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

    Artisan::call('keystone:end-sessions', ['--all' => true, '--operator' => 'margaret']);

    Auth::forgetGuards();
    $inAdasBrowser = $this->get(route('login'));

    $this->switchBackTo($gracesBrowser);
    $inGracesBrowser = $this->get(route('login'));

    $this->assertSignInPage($inAdasBrowser);
    $this->assertSignInPage($inGracesBrowser);
});

it("signs out the account's sessions through the job an admin panel dispatches", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Bus::dispatch(new EndSessions($user, operator: 'margaret')->onConnection('sync'));

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it("signs out every account's sessions through the job an admin panel dispatches", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Bus::dispatch(new EndAllSessions(operator: 'margaret')->onConnection('sync'));

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

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

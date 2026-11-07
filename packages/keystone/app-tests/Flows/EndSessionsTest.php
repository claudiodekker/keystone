<?php

use ClaudioDekker\Keystone\Jobs\EndAllSessions;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\Surface;
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

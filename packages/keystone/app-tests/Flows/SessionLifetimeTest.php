<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

it('signs out a session its absolute lifetime after the sign-in, and tells the user why', function () {
    $this->freezeTime();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-expired')]);
});

it('does not extend the lifetime for requests during it', function () {
    $this->freezeTime();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $lifetime = $this->absoluteLifetimeSeconds();

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($lifetime - 1)->seconds();
    $beforeTheEnd = $this->get(route('login'));

    Auth::forgetGuards();
    $this->travel(1)->second();
    $atTheEnd = $this->get(route('login'));

    $this->assertAlreadySignedIn($beforeTheEnd);
    $this->assertSignInPage($atTheEnd, ['status' => __('keystone::messages.session-expired')]);
});

it('keeps the session for good when the lifetime is null', function () {
    config(['keystone.session.absolute_lifetime_seconds' => null]);
    $this->freezeTime();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel(10)->years();

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('counts from the sign-in when the lifetime is turned on after it', function () {
    config(['keystone.session.absolute_lifetime_seconds' => null]);
    $this->freezeTime();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    config(['keystone.session.absolute_lifetime_seconds' => 60 * 60]);
    Auth::forgetGuards();
    $this->travel(30)->minutes();
    $live = $this->get(route('login'));

    Auth::forgetGuards();
    $this->travel(30)->minutes();
    $ended = $this->get(route('login'));

    $this->assertAlreadySignedIn($live);
    $this->assertSignInPage($ended, ['status' => __('keystone::messages.session-expired')]);
});

it('counts a session with no sign-in time as expired, however long the lifetime', function () {
    config(['keystone.session.absolute_lifetime_seconds' => 100 * 365 * 24 * 60 * 60]);
    $user = $this->createUser();

    $this->withSession([Keystone::guard()->getName() => $user->getAuthIdentifier()]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-expired')]);
});

it('counts a sign-in time in the future as expired', function () {
    $this->freezeTime();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel(-1)->second();

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => __('keystone::messages.session-expired')]);
});

it('leaves a guest who never signed in alone', function () {
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
    $this->assertSiteDataKept($response);
    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($this->securityEvents()->logged())->toBe([]);
});

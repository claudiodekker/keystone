<?php

use ClaudioDekker\Keystone\AppTests\Support\NotingExpiryResponse;
use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Auth;

it('signs out a session its absolute lifetime after the sign-in, and tells the user why', function () {
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

it('records session.ended with the reason expired for the account', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();
    $this->get(route('login'));

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SESSION_ENDED])
        ->and($events[1]->reason)->toBe('keystone.expired')
        ->and($events[1]->flow)->toBeNull();
});

it('records the expiry with the address of the request that found it', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get(route('login'));

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('ip_address')->all())->toBe(['203.0.113.9', '198.51.100.7']);
});

it("clears the browser's site data when the session expires", function () {
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

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
});

it('clears nothing on the page the user then signs in on', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();
    $this->get(route('login'));

    $response = $this->get(route('login'));

    $this->assertSiteDataKept($response);
});

it('tells a JSON client its session expired', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();

    $response = $this->getJson($this->protectedPage());

    $this->assertSessionExpiredForJson($response);
});

it('sends an HTML request to sign in, and back where it was going after', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $signIn = fn () => $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $signIn();

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();
    $expired = $this->get($this->protectedPage());

    Auth::forgetGuards();
    $next = $signIn();

    $this->assertSessionExpired($expired);
    $this->assertSignedIn($next, $this->protectedPage());
});

it("answers with the app's own expiry response, after ending and recording the session", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $answer = new NotingExpiryResponse($this->securityEvents());
    $this->app->instance(SessionExpiredResponse::class, $answer);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $this->travel($this->absoluteLifetimeSeconds())->seconds();
    $response = $this->get($this->protectedPage());

    $next = $this->get(route('login'));

    expect($response->getStatusCode())->toBe(418)
        ->and($answer->signedIn)->toBeFalse()
        ->and($answer->recorded)->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SESSION_ENDED]);

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
    $this->assertSignInPage($next, ['status' => __('keystone::messages.session-expired')]);
});

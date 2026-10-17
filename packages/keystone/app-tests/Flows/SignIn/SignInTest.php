<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([Login::class]);
});

it('signs a user in with their email address and secret', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it("dispatches Laravel's Login event for the user who signed in", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Event::assertDispatched(Login::class, fn (Login $event) => $event->user->is($user) && ! $event->remember);
});

it('gives the session a new id on sign-in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $cookie = config('session.cookie');

    $beforeSigningIn = $this->get(route('login'))->getCookie($cookie)->getValue();

    $this->withCookie($cookie, $beforeSigningIn)->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Auth::forgetGuards();
    $response = $this->flushSession()->withCookie($cookie, $beforeSigningIn)->get(route('login'));

    $this->assertSignInPage($response);
});

it('lands on the intended URL after signing in', function (string $intended) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->withSession(['url.intended' => $intended]);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/reports?period=7d');
})->with([
    'a URL on the app' => fn () => url('/reports?period=7d'),
    'a path' => '/reports?period=7d',
]);

it('lands on / when the intended URL is not a path on the app', function (string $intended) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->withSession(['url.intended' => $intended]);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
})->with([
    'another host' => 'https://evil.example/reports',
    'the app URL inside another URL' => fn () => 'https://evil.example/?next='.url('/reports'),
    'a scheme' => 'javascript:alert(1)',
    'a protocol-relative URL' => '//evil.example/reports',
    'a backslash' => '/\evil.example/reports',
    'a control character' => "/reports\n",
    'a delete character' => "/reports\x7F",
]);

it('refuses a signed-in user on the sign-in page', function () {
    $user = $this->createUser();

    $response = $this->actingAs($user)->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('refuses a signed-in user at the sign-in submit before checking the proof', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->actingAs($user)->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertAlreadySignedIn($response);
});

it('refuses a wrong secret, flashing back only the identifier', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));

    $next = $this->get(route('login'));

    $this->assertSignInPage($next);
});

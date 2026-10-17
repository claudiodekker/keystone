<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([Logout::class]);
});

it('signs the user out, and the next request is a guest again', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->post(route('logout'));

    $next = $this->get(route('login'));

    $this->assertSignedOut($response);
    $this->assertSignInPage($next, ['status' => __('keystone::messages.signed-out')]);
});

it('ends the whole session, so nothing it held outlives signing out', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->arrivingFrom(url('/reports'));
    $signedIn = $this->cookies();

    $this->post(route('logout'));

    $next = $this->withCookies($signedIn)->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($next, '/');
});

it("dispatches Laravel's Logout event for the user who signed out", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->post(route('logout'));

    Event::assertDispatched(Logout::class, fn (Logout $event) => $event->user->is($user));
});

it('signs out a guest the same way', function () {
    $response = $this->post(route('logout'));

    $next = $this->get(route('login'));

    $this->assertSignedOut($response);
    $this->assertSignInPage($next, ['status' => __('keystone::messages.signed-out')]);
});

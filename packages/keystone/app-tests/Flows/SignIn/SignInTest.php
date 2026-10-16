<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;

it('signs a user in with their email address and secret', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it('gives the session a new id on sign-in', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->get(route('login'));
    $beforeSigningIn = $this->browser();

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->returnWith($beforeSigningIn)->get(route('login'));

    $this->assertSignInPage($response);
});

it('lands on the intended URL after signing in', function (string $intended) {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->arrivingFrom($intended);

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
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->arrivingFrom($intended);

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

it('refuses a wrong secret, flashing back only the identifier', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $next = $this->get(route('login'));

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
    $this->assertSignInPage($next);
})->group('SI-11');

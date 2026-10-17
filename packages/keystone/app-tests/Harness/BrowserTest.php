<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use Illuminate\Support\Sleep;

it('starts each request without the user an earlier one held only in memory', function () {
    $user = $this->createUser();
    Keystone::guard()->setUser($user);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('keeps a sign-in for the next request through the session cookie', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('forgets every cookie in a fresh browser', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->freshBrowser()->get(route('login'));

    $this->assertSignInPage($response);
});

it('comes back with the cookies the browser held earlier', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $signedIn = $this->browser();
    $this->freshBrowser()->get(route('login'));

    $response = $this->returnWith($signedIn)->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('puts the intended URL where the next sign-in reads it', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $this->arrivingFrom(url('/reports'));

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/reports');
});

it('keeps the rest of the browser session when it puts the intended URL', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->arrivingFrom(url('/reports'));

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('puts nothing of an earlier browser in the session when it puts the intended URL', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->freshBrowser()->arrivingFrom(url('/reports'));

    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('keeps what app code leaves in the session for the next request', function () {
    $user = $this->createUser();

    $this->duringRequest(fn () => session()->put(Keystone::guard()->getName(), $user->getAuthIdentifier()));

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('keeps what each request flashed after a later request', function () {
    $refused = $this->post(route('login.submit', 'form'), [
        'identifier' => 'ada@example.com',
        'secret' => 'wrong horse',
    ]);

    $this->freshBrowser()->post(route('login.submit', 'form'), [
        'identifier' => 'grace@example.com',
        'secret' => 'wrong horse',
    ]);

    expect($refused->oldInput())->toBe(['identifier' => 'ada@example.com']);
});

it('measures each request on the test clock', function () {
    $startedAt = now();

    $response = $this->get(route('login'));

    Sleep::for(250)->milliseconds();

    expect($response->milliseconds())->toBe(0.0)
        ->and($startedAt->diffInMilliseconds(now()))->toBe(250.0);
});

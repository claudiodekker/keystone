<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Sleep;

it('forgets a user an earlier request held only in memory', function () {
    $user = $this->createUser();
    $this->actingAs($user)->get(route('login'));

    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('keeps a sign-in for the next request through the session cookie', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('sends none of the cookies earlier responses set after withoutCookies()', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->withoutCookies()->get(route('login'));

    $this->assertSignInPage($response);
});

it('sends the cookies it held earlier again with withCookies()', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $signedIn = $this->cookies();
    $this->withoutCookies()->get(route('login'));

    $response = $this->withCookies($signedIn)->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('keeps what the test puts in the session for the next request', function () {
    $user = $this->createUser();

    $this->withSession([Keystone::guard()->getName() => $user->getAuthIdentifier()]);

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('keeps what each request flashed after a later request', function () {
    $refused = $this->post(route('login.submit', 'form'), [
        'identifier' => 'ada@example.com',
        'secret' => 'wrong horse',
    ]);

    $this->withoutCookies()->post(route('login.submit', 'form'), [
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

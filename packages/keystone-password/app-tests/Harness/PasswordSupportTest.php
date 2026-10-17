<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Facades\Auth;

it('gives the contract AppTests the test support of the installed password type', function () {
    $names = collect($this->supportsFor(Surface::SIGN_IN))
        ->map(fn ($support) => $support->type()->name())
        ->all();

    expect($names)->toContain('password');
});

it('arranges a password hashed by the named driver', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    (new PasswordSupport)->arrangePassword($user, '  odd horse  ', 'argon');

    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => '  odd horse  ']);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

it('makes a driver refuse every hash', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = (new PasswordSupport)->arrangeCredential(Surface::SIGN_IN, $user);

    $support->refuseHashesOf(config('hashing.driver'));
    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', ...$support->validProof(Surface::SIGN_IN, $user)]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

<?php

use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Support\Facades\Auth;

it('treats surrounding spaces as part of the password', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    (new PasswordSupport)->arrangePassword($user, '  odd horse  ');

    $trimmed = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => 'odd horse']);

    $this->assertSignInRefused($trimmed, __('keystone::messages.credentials-rejected'));

    $spaced = $this->flushSession()->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => '  odd horse  ']);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($spaced, '/');
    $this->assertAlreadySignedIn($next);
});

it('signs in with a password of 1024 characters', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $password = str_repeat('ä', 1024);
    (new PasswordSupport)->arrangePassword($user, $password);

    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => $password]);

    Auth::forgetGuards();
    $next = $this->get(route('login'));

    $this->assertSignedIn($response, '/');
    $this->assertAlreadySignedIn($next);
});

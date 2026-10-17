<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('requires an identifier and the fields the type names', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $fields = array_keys($support->validProof(Surface::SIGN_IN, $this->createUser()));

    $response = $this->post(route('login.submit', $support->type()->name()));

    $this->assertSignInInvalid($response, [
        'identifier' => __('validation.required', ['attribute' => 'identifier']),
        ...$fields,
    ]);
});

it('refuses an identifier that is not a string', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => ['ada@example.com'],
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInInvalid($response, [
        'identifier' => __('validation.string', ['attribute' => 'identifier']),
    ]);
});

it('refuses an identifier longer than 255 characters, flashing back only the identifier', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => str_repeat('a', 244).'@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInInvalid($response, [
        'identifier' => __('validation.max.string', ['attribute' => 'identifier', 'max' => 255]),
    ]);
});

it('takes an identifier of 255 characters', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, str_repeat('a', 243).'@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => str_repeat('a', 243).'@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

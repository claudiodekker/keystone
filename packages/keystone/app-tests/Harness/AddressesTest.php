<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('adds a verified address, so the account no longer signs in with its unverified ones', function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($user, 'ada@work.example');
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@work.example',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('adds a made-up verified address when given none, and returns it', function () {
    $user = $this->createUser();
    $address = $this->addVerifiedAddress($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $address,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

it('adds an unverified address, which signs in neither of two accounts that hold it', function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $this->addUnverifiedAddress($ada, 'shared@example.com');
    $this->addUnverifiedAddress($grace, 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $ada);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('adds a verified address that outranks the unverified claim of another account', function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $this->addUnverifiedAddress($grace, 'shared@example.com');
    $this->addVerifiedAddress($ada, 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $ada);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]);

    $this->assertSignedIn($response, '/');
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

it('adds an unverified address that the verified claim of another account outranks', function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $this->addVerifiedAddress($ada, 'shared@example.com');
    $this->addUnverifiedAddress($grace, 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $grace);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

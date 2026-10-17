<?php

use ClaudioDekker\Keystone\Methods\Surface;

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

it('adds a verified and an unverified address, and only the verified one signs in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($user, 'ada@work.example');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@work.example',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

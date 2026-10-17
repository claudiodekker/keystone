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

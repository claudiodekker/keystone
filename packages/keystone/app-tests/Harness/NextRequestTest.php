<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('forgets a user an earlier request held only in memory', function () {
    $user = $this->createUser();
    $this->actingAs($user)->get(route('login'));

    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('keeps a sign-in for the next request', function () {
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

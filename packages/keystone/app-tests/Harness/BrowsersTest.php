<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('switches to a fresh browser that is not signed in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->switchToFreshBrowser();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('switches back to an earlier browser, still signed in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $first = $this->switchToFreshBrowser();
    $this->get(route('login'));

    $this->switchBackTo($first);
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

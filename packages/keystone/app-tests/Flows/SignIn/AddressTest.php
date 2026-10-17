<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('matches a typed address to its account whatever its case, Unicode form or IDN spelling', function (string $typed) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'rené@bücher.example');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $typed,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
})->with([
    'upper case' => 'RENÉ@BÜCHER.EXAMPLE',
    'a decomposed accent' => "rene\u{0301}@bücher.example",
    'the ASCII spelling of the domain' => 'rené@xn--bcher-kva.example',
]);

it('matches an address stored with surrounding spaces', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, ' ada@example.com  ');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

it('folds no accents away', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'rené@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'rene@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it("doesn't sign in with an unverified address while the account holds a verified one", function () {
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

it('signs in with an unverified address while the account holds no verified one', function () {
    $user = $this->createUser();
    $this->addUnverifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

it('signs in neither account that counts one address as verified', function () {
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

it("doesn't sign in with an address another account holds verified", function () {
    $ada = $this->createUser();
    $grace = $this->createUser();
    $this->addUnverifiedAddress($grace, 'shared@example.com');
    $this->addVerifiedAddress($ada, 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $grace);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('refuses an identifier that is no address like an unknown one', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('tells apart two domains IDN cannot spell in ASCII', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@one..example');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@two..example',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;

it('matches a typed address to its account whatever its case, Unicode form or IDN spelling', function (string $typed) {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'rené@bücher.example']);
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
])->group('H4');

it('matches an address stored with surrounding spaces', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => ' ada@example.com  ']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
})->group('H4');

it('folds no accents away', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'rené@example.com']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'rene@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
})->group('H4');

it('refuses an identifier that is no address like an unknown one', function () {
    $user = $this->createUser();
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@example.com']);
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
    UserEmail::factory()->for($user, 'user')->verified()->create(['address' => 'ada@one..example']);
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@two..example',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

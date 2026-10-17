<?php

use ClaudioDekker\Keystone\Methods\Surface;

it('answers an unknown address exactly like a wrong secret', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $unknown = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $unknown);
});

it('answers an address two accounts count as verified exactly like a wrong secret', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($this->createUser(), 'shared@example.com');
    $this->addUnverifiedAddress($this->createUser(), 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $unresolved = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $unresolved);
});

it('takes at least 300 ms to refuse', function (string $identifier) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addUnverifiedAddress($this->createUser(), 'shared@example.com');
    $this->addUnverifiedAddress($this->createUser(), 'shared@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $identifier,
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertTookAtLeast($response, 300);
})->with([
    'a wrong secret' => 'ada@example.com',
    'an unknown address' => 'grace@example.com',
    'an unresolved address' => 'shared@example.com',
]);

it('answers an identifier that is not valid UTF-8 exactly like a wrong secret', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $malformed = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
        'identifier' => "ada\xFF@example.com",
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertIndistinguishable($wrongSecret, $malformed);
});

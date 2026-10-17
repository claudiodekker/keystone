<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Sleep;

it('answers an unknown address exactly like a wrong secret', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $wrongSecret = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($wrongSecret, __('keystone::messages.credentials-rejected'));

    $unknown = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($unknown, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrongSecret, $unknown);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
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

    $this->assertSignInRefused($wrongSecret, __('keystone::messages.credentials-rejected'));

    $unresolved = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'shared@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($unresolved, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrongSecret, $unresolved);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
});

it('waits 300 ms before it refuses', function (string $identifier) {
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

    Sleep::assertSequence([Sleep::for(300)->milliseconds()]);
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

    $this->assertSignInRefused($wrongSecret, __('keystone::messages.credentials-rejected'));

    $malformed = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => "ada\xFF@example.com",
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($malformed, __('keystone::messages.credentials-rejected'));
    $this->assertIndistinguishable($wrongSecret, $malformed);
    Sleep::assertSequence([Sleep::for(300)->milliseconds(), Sleep::for(300)->milliseconds()]);
});

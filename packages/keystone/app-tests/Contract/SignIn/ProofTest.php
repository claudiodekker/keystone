<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Sleep;

beforeEach(function () {
    if ($this->supportsFor(Surface::SIGN_IN) === []) {
        $this->markTestSkipped('No installed credential type with test support serves sign-in.');
    }
});

it('signs in with a valid proof of every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $address = $this->addVerifiedAddress($user);
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $response = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $address,
            ...$support->validProof(Surface::SIGN_IN, $user),
        ]);

        $this->assertSignedIn($response, '/');
    }
});

it('refuses a rejected proof of every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $address = $this->addVerifiedAddress($user);
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $response = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
    }
});

it('answers no account exactly like a rejected proof, for every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $address = $this->addVerifiedAddress($user);
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $rejected = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $missing = $this->withoutCookies()->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'nobody.'.$address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $this->assertIndistinguishable($rejected, $missing);
    }

    Sleep::assertSequence(array_fill(0, 2 * count($this->supportsFor(Surface::SIGN_IN)), Sleep::for(300)->milliseconds()));
});

<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;

beforeEach(function () {
    if ($this->supportsFor(Surface::SIGN_IN) === []) {
        $this->markTestSkipped('No installed credential type with test support serves sign-in.');
    }
});

it('signs in with a valid proof of every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $email = UserEmail::factory()->for($user, 'user')->verified()->create();
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $response = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $email->address,
            ...$support->validProof(Surface::SIGN_IN, $user),
        ]);

        $this->assertSignedIn($response, '/');
    }
});

it('refuses a rejected proof of every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $email = UserEmail::factory()->for($user, 'user')->verified()->create();
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $response = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $email->address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
    }
});

it('answers no account exactly like a rejected proof, for every installed type that serves sign-in', function () {
    foreach ($this->supportsFor(Surface::SIGN_IN) as $support) {
        $user = $this->createUser();
        $email = UserEmail::factory()->for($user, 'user')->verified()->create();
        $support->arrangeCredential(Surface::SIGN_IN, $user);

        $rejected = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
            'identifier' => $email->address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $missing = $this->freshBrowser()->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'nobody.'.$email->address,
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

        $this->assertIndistinguishable($rejected, $missing);
        $this->assertTookAtLeast($missing, 300);
    }
})->group('SI-3', 'SI-11');

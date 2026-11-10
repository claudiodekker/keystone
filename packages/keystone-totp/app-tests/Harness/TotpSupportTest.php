<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\AppTests\TotpSupport;

it('gives the contract AppTests the test support of the installed TOTP type', function () {
    $names = collect($this->supportsFor(Surface::CHALLENGE))
        ->map(fn ($support) => $support->type()->name())
        ->all();

    expect($names)->toContain('totp');
});

it('arranges an imported key, which signs in with its own code', function () {
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeKey($user, 'JBSWY3DPEHPK3PXP');
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code(key: 'JBSWY3DPEHPK3PXP')]);

    $this->assertSignedIn($response, '/');
});

it('gives a rejected code no step of a wide window accepts', function () {
    config(['keystone.totp.window_steps' => 40]);
    $user = $this->createUser();
    $support = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'totp'), $support->rejectedProof(Surface::CHALLENGE));

    $this->assertChallengeRefused($response, 'code', __('keystone::messages.credential-invalid'));
});

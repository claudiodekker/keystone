<?php

use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use PHPUnit\Framework\AssertionFailedError;

it('arranges a set of codes, the last of which answers the challenge too', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $codes->code(7)]);

    $this->assertSignedIn($response, '/');
});

it('names the position when asked for a code it did not arrange', function () {
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $this->createUser());

    expect(fn () => $codes->code(8))->toThrow(AssertionFailedError::class, 'No recovery code was arranged at position [8].');
});

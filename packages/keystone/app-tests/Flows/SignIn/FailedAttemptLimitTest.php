<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

it('refuses the right secret as throttled after the allowance of wrong secrets in an hour', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it('leaves earlier failures counted after a success in between', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $half = intdiv($this->failedAttemptAllowance(), 2);
    $this->submitRejectedProofs($support, 'ada@example.com', $half);

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signIn, '/');

    Auth::forgetGuards();
    $this->flushSession();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() - $half);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it('gives a sign-in its own count back, and no more', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() - 1);

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signIn, '/');

    Auth::forgetGuards();
    $givenBack = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($givenBack, __('keystone::messages.credentials-rejected'));

    $spent = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInThrottled($spent);
});

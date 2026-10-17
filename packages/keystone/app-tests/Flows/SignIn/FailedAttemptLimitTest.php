<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
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

it('answers a real account, a made-up one and a garbage credential type alike once throttled', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());
    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance());

    $real = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $madeUp = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('login.submit', 'garbage'));
    }

    $garbage = $this->flushSession()->post(route('login.submit', 'garbage'));

    $this->assertSignInThrottled($real);
    $this->assertSignInThrottled($madeUp);
    $this->assertSignInThrottled($garbage);
    $this->assertIndistinguishable($real, $madeUp);
    $this->assertIndistinguishable($real, $garbage);
});

it('counts two spellings of a made-up address that would name the same account as one', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $half = intdiv($this->failedAttemptAllowance(), 2);
    $this->submitRejectedProofs($support, 'Grace@Example.com', $half);
    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance() - $half);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => ' GRACE@EXAMPLE.COM ',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInThrottled($response);
});

it('keeps a count each for two made-up addresses that differ only in an accent', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->submitRejectedProofs($support, 'rené@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'rene@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
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

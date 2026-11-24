<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\AppTests\TotpSupport;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it("shares a wrong code's failed-attempt count with the challenge", function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $totp = (new TotpSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->post(route('login.challenge.submit', 'totp'), ['code' => $totp->code(0)]);
    $firstFactor = $this->supportFor(Surface::SIGN_IN);

    Auth::forgetGuards();
    $this->travel($this->sudoLifetimeSeconds())->seconds();
    $this->get($this->sudoGatedPage());

    $sameDevice = $this->browserWithCopiedCookies();
    $signedIn = $this->switchToFreshBrowser();
    $this->switchBackTo($sameDevice);
    $this->passFirstFactor($user);
    $this->post(route('login.challenge.submit', 'totp'), $totp->rejectedProof(Surface::CHALLENGE));

    $this->switchBackTo($signedIn);
    $this->post(route('sudo.submit', $firstFactor->type()->name()), $firstFactor->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', 'totp'), ['code' => $totp->code(0)]);

    $this->assertThrottled($response);
});

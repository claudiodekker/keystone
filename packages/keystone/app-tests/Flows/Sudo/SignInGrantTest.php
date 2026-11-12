<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Auth;

it('grants sudo to a sign-in the challenge completed, recording it with the reason sign-in', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);

    Auth::forgetGuards();
    $response = $this->get($this->sudoGatedPage());

    $granted = $this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_GRANTED)->sole();

    expect($response->getStatusCode())->toBe(204)
        ->and($granted->reason)->toBe('keystone.sign_in');
});

it('grants sudo to a sign-in enrollment completed, recording it with the reason sign-in', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[2]]);

    Auth::forgetGuards();
    $response = $this->get($this->sudoGatedPage());

    $granted = $this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_GRANTED)->sole();

    expect($response->getStatusCode())->toBe(204)
        ->and($granted->reason)->toBe('keystone.sign_in');
});

it('signs in without a grant or its record from an address that has no subnet', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();

    $signedIn = $this->withServerVariables(['REMOTE_ADDR' => 'not-an-address'])->passFirstFactor($user);

    Auth::forgetGuards();
    $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson($this->sudoGatedPage());

    $this->assertSignedIn($signedIn, '/');
    $this->assertSudoRequiredForJson($response);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_GRANTED))->toBeEmpty();
});

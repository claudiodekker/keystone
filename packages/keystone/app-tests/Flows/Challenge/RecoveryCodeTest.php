<?php

use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

it('signs the held user in with one of their recovery codes, alerting the owner with the codes left', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $codes);

    $alert = $this->sentAlerts()->sole()['alert'];

    $this->assertSignedIn($response, '/');
    expect($alert->type)->toBe(SecurityEventType::RECOVERY_CODE_USED)
        ->and($alert->recoveryCodesLeft)->toBe(7);
});

it('refuses a spent code at the next challenge', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $codes);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->answerChallenge($user, $codes);

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
});

it('matches a code typed in lower case, with or without its dashes and with spaces', function (string $typed) {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8']);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $typed]);

    $this->assertSignedIn($response, '/');
})->with([
    'in lower case' => ['7kq2m-xw9pd-4hnrt-b3zcf-6jvgl-8'],
    'without dashes' => ['7KQ2MXW9PD4HNRTB3ZCF6JVGL8'],
    'with spaces for dashes' => ['7KQ2M XW9PD 4HNRT B3ZCF 6JVGL 8'],
    'with spaces around it' => [" 7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8\n"],
]);

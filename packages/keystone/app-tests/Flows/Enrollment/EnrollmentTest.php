<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Support\Facades\Notification;

it('holds a correct first factor for enrollment when the account holds no second factor', function () {
    $user = $this->createUser();

    $response = $this->passFirstFactor($user);

    $this->assertHeldForEnrollment($response);
});

it('signs the held user in once they type back one of the recovery codes it shows', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[5]]);

    $this->assertSignedIn($response, '/');
});

it('shows the same recovery codes again on a reload', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $shown = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $reloaded = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    expect($reloaded)->toBe($shown)
        ->and($shown)->toHaveCount(8);
});

it('refuses a code the recovery-code step never showed, saving nothing', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $this->get(route('login.enrollment'));

    $response = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => RecoveryCode::generate()->format()]);

    $this->assertEnrollmentRefused($response, 'recovery_code', __('keystone::messages.recovery-code-incorrect'));
    expect($this->securityEvents()->for($user)->pluck('type')->all())->not->toContain(SecurityEventType::RECOVERY_CODES_GENERATED);
});

it('saves the shown codes, so one of them answers a later challenge', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[2]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $codes[6]]);

    $this->assertSignedIn($response, '/');
});

it('saves only the shown codes, so a later challenge refuses a code the step never showed', function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[2]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => RecoveryCode::generate()->format()]);

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
});

it('records the saved codes in the enrollment flow, alerting nobody for a first set', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $support);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));

    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[0]]);

    $generated = $this->securityEvents()->for($user)->firstWhere('type', SecurityEventType::RECOVERY_CODES_GENERATED);

    expect($generated->flow)->toBe(Flow::ENROLLMENT)
        ->and($this->sentAlerts())->toBeEmpty();
});

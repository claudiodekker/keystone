<?php

use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use ClaudioDekker\Keystone\Totp\AppTests\TotpSupport;
use Illuminate\Support\Facades\Notification;

it('moves the hold on to the recovery codes once the first code of the key it shows is right', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    $response = $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $ceremony['key'])]);

    $next = $this->get(route('login.enrollment'));

    $this->assertSecondFactorEnrolled($response);
    $this->assertRecoveryCodesPage($next);
});

it("answers the next sign-in's challenge with the enrolled key", function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');
    $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $ceremony['key'])]);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[0]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => (new TotpSupport)->code(1, $ceremony['key'])]);

    $this->assertSignedIn($response, '/');
});

it('counts the step of the code that enrolled the key as accepted, refusing a code of that step or an earlier one', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');
    $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(1, $ceremony['key'])]);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[0]]);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->post(route('login.challenge.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $ceremony['key'])]);

    $this->assertChallengeRefused($response, 'code', __('keystone::messages.credential-invalid'));
});

it('shows a new 160-bit key in Base32 and as an otpauth URI labelled with the app and the account', function () {
    config(['app.name' => 'Acme Ledger']);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->passFirstFactor($user);

    $ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    expect($ceremony['key'])->toMatch('/\A[A-Z2-7]{32}\z/')
        ->and($ceremony['uri'])->toBe('otpauth://totp/Acme%20Ledger%3Aada%40example.com?secret='.$ceremony['key'].'&issuer=Acme%20Ledger');
});

it('shows the same key again on a reload', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $shown = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    $reloaded = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    expect($reloaded)->toBe($shown);
});

it('refuses a wrong code with the message on its field, storing nothing', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $shown = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    $response = $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(5, $shown['key'])]);

    $this->assertEnrollmentRefused($response, 'code', __('keystone::messages.credential-invalid'));
    expect(UserCredential::query()->where('user_id', $user->getAuthIdentifier())->where('type', 'totp')->exists())->toBeFalse();
});

it('keeps the key it shows after a wrong code', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $shown = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');
    $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(5, $shown['key'])]);

    $response = $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $shown['key'])]);

    $this->assertSecondFactorEnrolled($response);
});

it('refuses the answer to a key that expired with its message', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $shown = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    $this->travel(10)->minutes();
    $response = $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $shown['key'])]);

    $this->assertEnrollmentExpired($response, 'code');
});

it('shows a new key once the shown one expired', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $shown = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');
    $this->travel(10)->minutes();
    $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $shown['key'])]);

    $fresh = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    expect($fresh['key'])->not->toBe($shown['key']);
});

it('records the added key in the enrollment flow and alerts the owner', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

    $this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $ceremony['key'])]);

    $added = $this->securityEvents()->for($user)->firstWhere('type', SecurityEventType::CREDENTIAL_ADDED);

    expect($added->flow)->toBe(Flow::ENROLLMENT)
        ->and($added->credential_type)->toBe('totp')
        ->and($this->sentAlerts()->sole()['alert']->type)->toBe(SecurityEventType::CREDENTIAL_ADDED);
});

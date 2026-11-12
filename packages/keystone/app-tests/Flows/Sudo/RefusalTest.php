<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('refuses a wrong first factor with the flattened message, records it in the sudo flow and alerts the owner from a known device too', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $wrong = $support->rejectedProof(Surface::SIGN_IN);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $wrong);

    $failed = $this->securityEvents()->for($user)->last();

    $this->assertSudoRefused($response, array_key_first($wrong), __('keystone::messages.credential-invalid'));
    expect($failed->type)->toBe(SecurityEventType::SUDO_FAILED)
        ->and($failed->flow)->toBe(Flow::SUDO)
        ->and($failed->credential_type)->toBe($support->type()->name())
        ->and($this->sentAlerts()->pluck('alert.type')->all())->toContain(SecurityEventType::SUDO_FAILED);
});

it('refuses an answer from an address that has no subnet as a failed one', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $proof = $support->validProof(Surface::SIGN_IN, $user);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->withServerVariables(['REMOTE_ADDR' => 'not-an-address'])->post(route('sudo.submit', $support->type()->name()), $proof);

    $failed = $this->securityEvents()->for($user)->last();

    $this->assertSudoRefused($response, array_key_first($proof), __('keystone::messages.credential-invalid'));
    expect($failed->type)->toBe(SecurityEventType::SUDO_FAILED)
        ->and($failed->reason)->toBe('keystone.unbindable_subnet');
});

it('counts an answer from an address that has no subnet against the failed-attempt limit', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $proof = $support->validProof(Surface::SIGN_IN, $user);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => 'not-an-address'])->post(route('sudo.submit', $support->type()->name()), $proof);

    Auth::forgetGuards();
    $response = $this->fromOwnNetwork()->post(route('sudo.submit', $support->type()->name()), $proof);

    $this->assertThrottled($response);
});

it("keeps a count of its own, apart from sign-in's", function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $address = $this->addVerifiedAddress($user);
    $this->get($this->sudoGatedPage());

    $sameDevice = $this->browserWithCopiedCookies();
    $signedIn = $this->switchToFreshBrowser();
    $this->switchBackTo($sameDevice);
    $this->post(route('login.submit', $support->type()->name()), ['identifier' => $address, ...$support->rejectedProof(Surface::SIGN_IN)]);

    $this->switchBackTo($signedIn);
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertSudoGranted($response, $this->sudoGatedPage());
});

it('verifies nothing and takes no attempt for an answer without a sudo-in-progress', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $recorded = $this->securityEvents()->for($user)->count();

    $stray = $this->post(route('sudo.submit', $support->type()->name()), $support->rejectedProof(Surface::SIGN_IN));

    Auth::forgetGuards();
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertNoSudoInProgress($stray, '/');
    $this->assertSudoGranted($response, $this->sudoGatedPage());
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_FAILED))->toBeEmpty()
        ->and($this->securityEvents()->for($user)->count())->toBe($recorded + 1);
});

it('refuses a type the step does not offer without verifying it or taking an attempt', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $firstFactor = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated);

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $firstFactor->type()->name()), $firstFactor->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $notOffered = $this->post(route('sudo.submit', 'recovery-code'), ['recovery_code' => 'ABCDE-23456']);

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $secondFactor->type()->name()), $secondFactor->validProof(Surface::CHALLENGE, $user));

    expect($notOffered->getStatusCode())->toBe(404);
    $this->assertSudoGranted($response, $gated);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_FAILED))->toBeEmpty();
});

<?php

use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('grants sudo for a recovery code at the second step, spending it', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $firstFactor = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated);

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $firstFactor->type()->name()), $firstFactor->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', 'recovery-code'), $codes->validProof(Surface::CHALLENGE, $user));

    $this->assertSudoGranted($response, $gated);
    expect(UserRecoveryCode::query()->where('user_id', $user->getAuthIdentifier())->count())->toBe(7);
});

it('keeps the last recovery code at the second step even while recovery codes are optional, granting nothing', function () {
    config(['keystone.require_recovery_codes' => false]);
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCodes($user, ['ABCDE-23456']);
    $firstFactor = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated);

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $firstFactor->type()->name()), $firstFactor->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', 'recovery-code'), $codes->validProof(Surface::CHALLENGE, $user));

    $this->assertSudoLastRecoveryCodeKept($response);
    expect(UserRecoveryCode::query()->where('user_id', $user->getAuthIdentifier())->count())->toBe(1);
});

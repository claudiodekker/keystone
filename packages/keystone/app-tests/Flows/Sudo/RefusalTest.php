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

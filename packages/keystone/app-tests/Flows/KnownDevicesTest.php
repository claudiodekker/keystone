<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it('alerts the owner when the account signs in from a browser it has not seen', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->all())->toBe(['ada@example.com'])
        ->and($alerts[0]['alert'])->toBeInstanceOf(SecurityAlert::class)
        ->and($alerts[0]['alert']->type)->toBe(SecurityEventType::SIGNED_IN)
        ->and($this->securityEvents()->for($user)->where('type', SecurityEventType::SIGNED_IN)->pluck('known_device')->all())->toBe([false]);
});

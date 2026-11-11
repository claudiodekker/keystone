<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutEnrollmentMandates();

    Notification::fake();
});

it('alerts the owner once a browser the account does not know has left the challenge for 7 minutes', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->passFirstFactor($user);

    $this->travel(7)->minutes();
    $this->artisan('keystone:alert-abandoned-challenges');

    $abandoned = $this->securityEvents()->for($user)->where('type', SecurityEventType::CHALLENGE_ABANDONED);

    expect($this->sentAlerts()->pluck('alert.type')->all())->toBe([SecurityEventType::CHALLENGE_ABANDONED])
        ->and($abandoned->pluck('ip_address')->all())->toBe(['198.51.100.23']);
});

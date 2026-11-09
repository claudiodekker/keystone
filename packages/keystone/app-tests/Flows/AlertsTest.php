<?php

use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it("mails each of the account's verified addresses when an operator ends its sessions", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $this->addVerifiedAddress($user, 'ada@work.example');

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);

    $alerts = $this->sentAlerts();

    expect($alerts->pluck('address')->sort()->values()->all())->toBe(['ada@example.com', 'ada@work.example'])
        ->and($alerts->pluck('alert')->every(fn ($alert) => $alert instanceof SecurityAlert && $alert->type === SecurityEventType::SESSIONS_TERMINATED))->toBeTrue();
});

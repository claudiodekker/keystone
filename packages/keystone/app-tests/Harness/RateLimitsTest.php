<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

it('reads the failed-attempt allowance from the live config, as the limiter does', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 2]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    expect($this->failedAttemptAllowance())->toBe(2);
    $this->assertSignInThrottled($response);
});

it('reads a request allowance from the live config, as the limiter does', function () {
    config(['keystone.rate_limits.requests_per_minute.view' => 3]);

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->get(route('login'));
    }

    $response = $this->get(route('login'));

    expect($this->requestAllowance(StepKind::VIEW))->toBe(3);
    $this->assertSignInThrottled($response);
});

it('counts rate limits in the store given to countLimitsIn', function () {
    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->get(route('login'));
    }

    $this->countLimitsIn(Cache::repository(new ArrayStore));
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('starts each test from an empty limiter store', function () {
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->get(route('login'));
    }
})->repeat(2);

it('submits the rejected proof the given number of times, each from its own address', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->submitRejectedProofs($support, 'ada@example.com', 3);

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::PROOF_REJECTED, SecurityEventType::PROOF_REJECTED, SecurityEventType::PROOF_REJECTED])
        ->and($events->pluck('ip_address')->unique())->toHaveCount(3);
});

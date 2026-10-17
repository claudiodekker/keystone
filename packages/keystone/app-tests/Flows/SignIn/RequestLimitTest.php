<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;

it('refuses the sign-in submit over the allowance before validation, even with an empty payload', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->post(route('login.submit', $support->type()->name()));
    }

    $response = $this->post(route('login.submit', $support->type()->name()));

    $this->assertSignInThrottled($response);
});

it('refuses the sign-in page over the view allowance', function () {
    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->get(route('login'));
    }

    $response = $this->get(route('login'));

    $this->assertSignInThrottled($response);
});

it('counts every address in one IPv6 /64 as one', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.dechex($i)])->post(route('login.submit', $support->type()->name()));
    }

    $sameSubnet = $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::ffff:1'])->post(route('login.submit', $support->type()->name()));
    $nextSubnet = $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:0:1::1'])->post(route('login.submit', $support->type()->name()));

    $this->assertSignInThrottled($sameSubnet);
    $this->assertSignInInvalid($nextSubnet, ['identifier']);
});

it('counts an IPv4-mapped IPv6 address as the IPv4 address it wraps', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:203.0.113.9'])->post(route('login.submit', $support->type()->name()));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('login.submit', $support->type()->name()));

    $this->assertSignInThrottled($response);
});

it('counts every request without a usable address together', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => ''])->post(route('login.submit', $support->type()->name()));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => 'garbage'])->post(route('login.submit', $support->type()->name()));

    $this->assertSignInThrottled($response);
});

it('records limit.tripped once for a signed-in account whose request limit trips', function () {
    $user = $this->createUser();

    foreach (range(1, $this->requestAllowance(StepKind::VIEW) + 3) as $i) {
        $this->actingAs($user)->get(route('login'));
    }

    $tripped = $this->securityEvents()->for($user)->where('type', SecurityEventType::LIMIT_TRIPPED)->values();

    expect($tripped)->toHaveCount(1)
        ->and($tripped[0]->reason)->toBe('keystone.request_limit')
        ->and($tripped[0]->credential_type)->toBeNull();
});

it('logs limit.tripped for a guest whose request limit trips', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT) + 3) as $i) {
        $this->post(route('login.submit', $support->type()->name()));
    }

    $lines = collect($this->securityEvents()->logged())->where('type', 'limit.tripped')->values();

    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['user_id'])->toBeNull()
        ->and($lines[0]['reason'])->toBe('keystone.request_limit');
});

it('counts the requests of a signed-in account together, whatever address they come from', function () {
    $user = $this->createUser();

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.0.'.intdiv($i, 256).'.'.($i % 256)])->get(route('login'));
    }

    $response = $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])->get(route('login'));

    $this->assertSignInThrottled($response);
});

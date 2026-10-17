<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;

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

it('counts the requests of a signed-in account together, whatever address they come from', function () {
    $user = $this->createUser();

    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.0.'.intdiv($i, 256).'.'.($i % 256)])->get(route('login'));
    }

    $response = $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '10.1.0.1'])->get(route('login'));

    $this->assertSignInThrottled($response);
});

<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('ends a grant its lifetime after it was granted, however busy the session stays', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $gated = $this->sudoGatedPage();
    $lifetime = $this->sudoLifetimeSeconds();

    foreach (range(1, 3) as $request) {
        Auth::forgetGuards();
        $this->travel(intdiv($lifetime, 4))->seconds();
        $this->get($gated);
    }

    Auth::forgetGuards();
    $this->travel($lifetime - 1 - 3 * intdiv($lifetime, 4))->seconds();
    $beforeTheEnd = $this->getJson($gated);

    Auth::forgetGuards();
    $this->travel(1)->second();
    $atTheEnd = $this->getJson($gated);

    expect($beforeTheEnd->getStatusCode())->toBe(204);
    $this->assertSudoRequiredForJson($atTheEnd);
});

it('shortens a grant already held when the lifetime is lowered', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    Auth::forgetGuards();
    $this->travel(5 * 60)->seconds();
    config(['keystone.sudo.lifetime_seconds' => 5 * 60]);
    $response = $this->getJson($this->sudoGatedPage());

    $this->assertSudoRequiredForJson($response);
});

it('forgets a grant whose time is in the future', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated);

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $this->travel(-1)->second();
    $response = $this->getJson($gated);

    $this->assertSudoRequiredForJson($response);
});

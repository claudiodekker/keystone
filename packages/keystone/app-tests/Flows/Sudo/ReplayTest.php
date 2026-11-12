<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('grants sudo for the first factor of an account holding no second factor, and sends the user on to the gated page', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();

    Auth::forgetGuards();
    $this->travel($this->sudoLifetimeSeconds())->seconds();
    $this->get($gated);

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $next = $this->get($gated);

    $this->assertSudoGranted($response, $gated);
    expect($next->getStatusCode())->toBe(204);
});

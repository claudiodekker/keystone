<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('brings no grant to a remembered return', function () {
    $this->rememberLifetimeSeconds();
    $user = $this->createUser();
    $address = $this->addVerifiedAddress($user);
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $address,
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());
    $response = $this->get($this->sudoGatedPage());

    $this->assertSudoRequired($response);
    expect($this->securityEvents()->for($user)->where('type', SecurityEventType::SUDO_GRANTED))->toHaveCount(1);
});

it('drops a live grant the session held when a remembered return restores it past its absolute lifetime', function () {
    $this->rememberLifetimeSeconds();
    config(['keystone.session.absolute_lifetime_seconds' => 60]);
    $user = $this->createUser();
    $address = $this->addVerifiedAddress($user);
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => $address,
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    Auth::forgetGuards();
    $this->travel(60)->seconds();
    $response = $this->get($this->sudoGatedPage());

    $this->assertSudoRequired($response);
});

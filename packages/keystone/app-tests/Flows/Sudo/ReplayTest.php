<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
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

it('records the grant with the type and credential that answered, in the sudo flow', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $granted = $this->securityEvents()->for($user)->last();

    expect($granted->type)->toBe(SecurityEventType::SUDO_GRANTED)
        ->and($granted->flow)->toBe(Flow::SUDO)
        ->and($granted->credential_type)->toBe($support->type()->name())
        ->and($granted->credential_id)->not->toBeNull()
        ->and($granted->reason)->toBeNull();
});

it('gives the session a new id when it grants sudo', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $cookie = config('session.cookie');

    $beforeGranting = $this->get($this->sudoGatedPage())->getCookie($cookie)->getValue();

    Auth::forgetGuards();
    $response = $this->withCookie($cookie, $beforeGranting)->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    expect($response->getCookie($cookie)->getValue())->not->toBe($beforeGranting);
});

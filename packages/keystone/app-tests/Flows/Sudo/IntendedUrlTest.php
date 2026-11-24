<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('sends the user back to the page a refused mutation came from once granted, never replaying it', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->from(url('/settings/security'))->post($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertSudoGranted($response, '/settings/security');
});

it('sends the user to the root once granted when a refused mutation came from another site', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->withHeader('Referer', 'https://elsewhere.example/settings')->post($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertSudoGranted($response, '/');
});

it("keeps where a sudo-in-progress goes when a later refusal isn't a browser's GET", function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated.'?tab=keys');

    Auth::forgetGuards();
    $this->from(url('/settings/security'))->post($gated);

    Auth::forgetGuards();
    $this->getJson($gated.'?tab=sessions');

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertSudoGranted($response, $gated.'?tab=keys');
});

it("sends the user to the page of a later browser's GET once granted", function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $gated = $this->sudoGatedPage();
    $this->get($gated.'?tab=keys');

    Auth::forgetGuards();
    $this->get($gated.'?tab=sessions');

    Auth::forgetGuards();
    $response = $this->post(route('sudo.submit', $support->type()->name()), $support->validProof(Surface::SIGN_IN, $user));

    $this->assertSudoGranted($response, $gated.'?tab=sessions');
});

<?php

use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('shows the types serving sign-in the account holds at the first step, the first one preselected', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $response = $this->get(route('sudo'));

    $this->assertSudoPage($response, [
        'preselected' => $support->type()->name(),
        'types' => [['name' => $support->type()->name(), 'shape' => 'form']],
    ]);
});

it('shows the second factors the account holds at the second step, then its recovery codes, the first one preselected', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $firstFactor = $this->supportFor(Surface::SIGN_IN);
    $this->get($this->sudoGatedPage());

    Auth::forgetGuards();
    $this->post(route('sudo.submit', $firstFactor->type()->name()), $firstFactor->validProof(Surface::SIGN_IN, $user));

    Auth::forgetGuards();
    $response = $this->get(route('sudo'));

    $this->assertSudoPage($response, [
        'preselected' => $secondFactor->type()->name(),
        'types' => [
            ['name' => $secondFactor->type()->name(), 'shape' => 'form'],
            ['name' => 'recovery-code', 'shape' => 'form'],
        ],
    ]);
});

it('sends a session without a sudo-in-progress on to the intended URL', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);

    $response = $this->withSession(['url.intended' => '/reports?period=7d'])->get(route('sudo'));

    $this->assertNoSudoInProgress($response, '/reports?period=7d');
});

it('sends a session without a sudo-in-progress or an intended URL on to the root', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);

    $response = $this->get(route('sudo'));

    $this->assertNoSudoInProgress($response, '/');
});

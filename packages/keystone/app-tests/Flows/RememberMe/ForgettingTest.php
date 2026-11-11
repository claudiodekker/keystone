<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('forgets only the remember-me cookie of the browser that signs out', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $atHome = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($atHome);
    $home = $this->browserWithCopiedCookies();
    $this->switchToFreshBrowser();

    $atWork = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($atWork);
    $work = $this->browserWithCopiedCookies();
    $this->post(route('logout'));

    $this->switchBackTo($work);
    $signedOut = $this->get(route('login'));

    $this->switchBackTo($home);
    $remembered = $this->get(route('login'));

    $this->assertSignInPage($signedOut, ['status' => null]);
    $this->assertAlreadySignedIn($remembered);
});

it('forgets the remember-me token of a session demoted because its account newly owes enrollment', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $remembered = $this->browserWithCopiedCookies();
    config(['keystone.require_second_factor' => true]);
    Auth::forgetGuards();
    $demoted = $this->get(route('login'));

    config(['keystone.require_second_factor' => false]);
    $this->switchBackTo($remembered);

    $response = $this->get(route('login'));

    $this->assertSignInPage($demoted, ['status' => __('keystone::messages.session-demoted')]);
    $this->assertSignInPage($response, ['status' => null]);
});

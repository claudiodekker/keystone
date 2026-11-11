<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRememberToken;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('signs a browser that ticked remember me back in once its session is gone', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('leaves a browser that did not tick remember me signed out once its session is gone', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('remembers a browser that ticked remember me once it answers the challenge', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $firstFactor = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $this->post(route('login.submit', $firstFactor->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$firstFactor->validProof(Surface::SIGN_IN, $user),
    ]);

    $signedIn = $this->answerChallenge($user, $secondFactor);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('remembers a browser that ticked remember me once it saves the recovery codes its account owed', function () {
    config(['keystone.require_recovery_codes' => true]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $firstFactor = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $secondFactor = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

    $this->post(route('login.submit', $firstFactor->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$firstFactor->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->answerChallenge($user, $secondFactor);
    $codes = $this->shownRecoveryCodes($this->get(route('login.enrollment')));
    $signedIn = $this->post(route('login.enrollment.submit', 'recovery-code'), ['recovery_code' => $codes[3]]);

    $this->keepCookiesFrom($signedIn);
    $this->switchBackTo($this->browserWithCopiedCookies());

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('ignores a ticked box while the remember-me lifetime is zero', function () {
    config(['keystone.remember.lifetime_seconds' => 0]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signedIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signedIn, '/');
    expect($signedIn->getCookie('__Host-keystone_remember', decrypt: false))->toBeNull()
        ->and(UserRememberToken::query()->exists())->toBeFalse();
});

it('hands the browser a host-only remember-me cookie of 256 random bits that only HTTPS carries, scripts never read and cross-site requests leave behind, for the lifetime', function () {
    config(['keystone.remember.lifetime_seconds' => 17 * 24 * 60 * 60]);
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        'remember' => true,
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $cookie = $response->getCookie('__Host-keystone_remember', decrypt: false);

    $this->assertSignedIn($response, '/');
    expect($cookie->getValue())->toMatch('/^[0-9a-f]{64}$/')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBe(now()->getTimestamp() + 1468800);
});

it('offers the remember-me box on the sign-in page only while remember me is on', function (int $lifetime, bool $offered) {
    config(['keystone.remember.lifetime_seconds' => $lifetime]);

    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['remember' => $offered]);
})->with([
    'on' => [60 * 60, true],
    'off' => [0, false],
]);

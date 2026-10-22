<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;

it('puts the hardening floor on the sign-in page', function () {
    $response = $this->get(route('login'));

    $this->assertHardened($response);
});

it('puts the hardening floor on a refused sign-in redirect', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertHardened($response);
});

it('puts the hardening floor on a throttled answer', function () {
    foreach (range(1, $this->requestAllowance(StepKind::VIEW)) as $i) {
        $this->get(route('login'));
    }

    $response = $this->get(route('login'));

    $this->assertSignInThrottled($response);
    $this->assertHardened($response);
});

it('puts the hardening floor on a sign-in for a credential type the page does not offer', function () {
    $response = $this->post(route('login.submit', 'unknown'));

    expect($response->getStatusCode())->toBe(404);

    $this->assertHardened($response);
});

it('puts the hardening floor on the sign-out redirect', function () {
    $response = $this->post(route('logout'));

    $this->assertHardened($response);
});

it('replaces a looser header the app set', function () {
    $this->appSetsHeaders([
        'Cache-Control' => 'public, max-age=3600',
        'Referrer-Policy' => 'unsafe-url',
        'X-Frame-Options' => 'SAMEORIGIN',
    ]);

    $response = $this->get(route('login'));

    $this->assertHardened($response);
});

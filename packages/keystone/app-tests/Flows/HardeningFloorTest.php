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

it("keeps the app's own policy directives and closes the other three in each policy", function () {
    $this->appSetsHeaders([
        'Content-Security-Policy' => [
            "default-src 'self'; script-src 'self' 'nonce-abc'; object-src *",
            "img-src https://img.example.com, style-src 'self'; base-uri 'self'",
        ],
    ]);

    $response = $this->get(route('login'));

    $this->assertContentSecurityPolicies($response, [
        [
            'base-uri' => "'none'",
            'default-src' => "'self'",
            'frame-ancestors' => "'none'",
            'object-src' => "'none'",
            'script-src' => "'self' 'nonce-abc'",
        ],
        [
            'base-uri' => "'none'",
            'frame-ancestors' => "'none'",
            'img-src' => 'https://img.example.com',
            'object-src' => "'none'",
        ],
        [
            'base-uri' => "'none'",
            'frame-ancestors' => "'none'",
            'object-src' => "'none'",
            'style-src' => "'self'",
        ],
    ]);
});

it("does not let the app's frame-ancestors loosen the floor", function () {
    $this->appSetsHeaders(['Content-Security-Policy' => 'frame-ancestors *; frame-ancestors https://other.example']);

    $response = $this->get(route('login'));

    $this->assertHardened($response);
});

it('adds a policy of its own when the app sends none', function () {
    $response = $this->get(route('login'));

    $this->assertContentSecurityPolicies($response, [
        [
            'base-uri' => "'none'",
            'frame-ancestors' => "'none'",
            'object-src' => "'none'",
        ],
    ]);
});

it('lets the sources in hardening.frame_ancestors frame the sign-in page', function () {
    config(['keystone.hardening.frame_ancestors' => ['https://portal.example.com', 'https://*.partner.example']]);
    $this->appSetsHeaders(['X-Frame-Options' => 'SAMEORIGIN']);

    $response = $this->get(route('login'));

    $this->assertFramableBy($response, ['https://portal.example.com', 'https://*.partner.example']);
});

it('lets nobody frame the sign-in page when hardening.frame_ancestors is not a list of sources', function (mixed $value) {
    config(['keystone.hardening.frame_ancestors' => $value]);

    $response = $this->get(route('login'));

    $this->assertHardened($response);
})->with([
    'a string' => 'https://portal.example.com',
    'a map' => [['host' => 'https://portal.example.com']],
    'a source that ends the directive' => [['https://portal.example.com; script-src *']],
    'two sources in one' => [['https://a.example https://b.example']],
    'a non-string source' => [[1]],
]);

it("limits every policy to the framing sources, the app's own second policy included", function () {
    config(['keystone.hardening.frame_ancestors' => ['https://portal.example.com']]);
    $this->appSetsHeaders(['Content-Security-Policy' => "default-src 'self', frame-ancestors *"]);

    $response = $this->get(route('login'));

    $this->assertFramableBy($response, ['https://portal.example.com']);
});

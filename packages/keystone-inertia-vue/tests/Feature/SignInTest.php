<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(SignInAssertions::class);

it('renders the sign-in page with the page value\'s fields', function () {
    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Login')
        ->where('types', [['type' => 'form', 'shape' => 'form'], ['type' => 'password', 'shape' => 'form']])
        ->where('status', null));
});

it('tells the sign-in page whether to offer remember-me', function (int $lifetimeSeconds, bool $offered) {
    config(['keystone.remember.lifetime_seconds' => $lifetimeSeconds]);

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('rememberOffered', $offered));
})->with(['on' => [2592000, true], 'off' => [0, false]]);

it('renders the sign-in page with no identifier when nothing was refused', function () {
    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('identifier', null));
});

it('fills the sign-in page with the identifier a refused sign-in flashed back', function () {
    $this->arrangeCredential($this->createAccount(), new PasswordTypeSupport, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', ...(new PasswordTypeSupport)->rejectedProof(Surface::SIGN_IN)]);

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('identifier', 'jane@example.com'));
});

it('fills the sign-in page with the identifier that invalid input flashed back', function () {
    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com']);

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login')->where('identifier', 'jane@example.com'));
});

it('encrypts the sign-in page in the browser\'s history', function () {
    $response = $this->get(route('login'));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('sends a refused sign-in back to the sign-in page with the message on the identifier', function () {
    $this->arrangeCredential($this->createAccount(), new PasswordTypeSupport, Surface::SIGN_IN);

    $response = $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', ...(new PasswordTypeSupport)->rejectedProof(Surface::SIGN_IN)]);

    $this->assertSignInRefused($response);
});

it('sends the signed-in user on to the intended URL', function () {
    $this->arrangeCredential($this->createAccount(), new PasswordTypeSupport, Surface::SIGN_IN);
    session()->put('url.intended', '/dashboard');

    $response = $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', ...(new PasswordTypeSupport)->validProof(Surface::SIGN_IN)]);

    $this->assertSignedIn($response, '/dashboard');
});

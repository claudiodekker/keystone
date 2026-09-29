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
        ->component('keystone/SignIn')
        ->where('types', [['type' => 'form', 'shape' => 'form'], ['type' => 'password', 'shape' => 'form']])
        ->where('status', null));
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

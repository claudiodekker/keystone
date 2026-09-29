<?php

use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;

pest()->extend(StubsTestCase::class)->use(SignInAssertions::class, SignOutAssertions::class);

it('sends the signed-out user to the sign-in page, saying they signed out', function () {
    $support = new PasswordTypeSupport;
    $this->arrangeCredential($this->createAccount(), $support, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);

    $response = $this->post(route('logout'));

    $this->assertSignedOut($response);
    $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.signed-out'));
});

it('clears the browser\'s history once signed out', function () {
    $support = new PasswordTypeSupport;
    $this->arrangeCredential($this->createAccount(), $support, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);
    $this->post(route('logout'));

    $response = $this->get(route('login'));

    expect($response->inertiaPage())->toHaveKey('clearHistory', true);
});

it('leaves the browser\'s history alone while nobody signed out', function () {
    $response = $this->get(route('login'));

    expect($response->inertiaPage())->not->toHaveKey('clearHistory');
});

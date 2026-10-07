<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignInAssertions::class), AppTestCase::assertions(SignOutAssertions::class));

beforeEach(function () {
    $this->withoutMandates();

    if (config('keystone.remember.lifetime_seconds') === 0) {
        $this->markTestSkipped('The app turned remember-me off.');
    }

    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');
});

it('brings a ticked sign-in back signed in once its session is gone', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $response = $this->submitSignIn($support, 'jane@example.com', [...$support->validProof(Surface::SIGN_IN), 'remember' => '1']);
    $this->assertSignedIn($response, '/');
    session()->invalidate();
    $this->fromRememberCookie($this->rememberCookieOf($response))->fromDevice($this->deviceCookieOf($response));

    $this->get('keystone-app-tests/signed-in-only');

    $this->assertAuthenticatedAs($account);
});

it('leaves a sign-in that did not tick the box a guest once its session is gone', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $response = $this->submitSignIn($support, 'jane@example.com', $support->validProof(Surface::SIGN_IN));
    session()->invalidate();
    $this->fromRememberCookie($this->rememberCookieOf($response))->fromDevice($this->deviceCookieOf($response));

    $this->get('keystone-app-tests/signed-in-only');

    $this->assertGuest();
});

it('stops bringing the browser back once it signs out', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $response = $this->submitSignIn($support, 'jane@example.com', [...$support->validProof(Surface::SIGN_IN), 'remember' => '1']);
    $this->fromRememberCookie($this->rememberCookieOf($response))->fromDevice($this->deviceCookieOf($response));
    $this->assertSignedOut($this->post(route('logout')));
    session()->invalidate();

    $this->get('keystone-app-tests/signed-in-only');

    $this->assertGuest();
});

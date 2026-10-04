<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SessionExpiryAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignInAssertions::class), AppTestCase::assertions(SessionExpiryAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
    $this->lifetime = config('keystone.session.absolute_lifetime_seconds');

    if ($this->lifetime === null) {
        $this->markTestSkipped('The app turned the absolute session lifetime off.');
    }

    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
    Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');
    $this->freezeSecond();
});

it('keeps a session signed in until its absolute lifetime has passed', function () {
    $account = $this->signInAccount($this->support);

    $this->travel($this->lifetime - 1)->seconds();
    $this->get('keystone-app-tests/signed-in-only');

    $this->assertAuthenticatedAs($account);
});

it('ends a session once its absolute lifetime has passed, however active it was', function () {
    $account = $this->signInAccount($this->support);
    $this->travel($this->lifetime - 1)->seconds();
    $this->get('keystone-app-tests/signed-in-only');
    $this->travel(1)->seconds();

    $response = $this->get('keystone-app-tests/signed-in-only');

    $this->assertExpiredSessionRefused($response);
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $account->getKey(), 'reason' => 'expired']);
    $types = (array) config('keystone.clear_site_data');
    expect($response->headers->get('Clear-Site-Data'))->toBe($types === [] ? null : implode(', ', array_map(fn (string $type) => "\"{$type}\"", $types)));
});

it('tells the user on the sign-in page that their session expired', function () {
    $this->signInAccount($this->support);
    $this->travel($this->lifetime)->seconds();
    $this->get('keystone-app-tests/signed-in-only');

    $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.session-expired'));
});

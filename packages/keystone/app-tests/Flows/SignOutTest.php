<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignOutAssertions::class));

it('signs out, ending the session and regenerating the CSRF token', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => $support->type()]), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);
    session()->put('app-data', 'dropped on sign-out');
    [$sessionId, $token] = [session()->getId(), session()->token()];

    $response = $this->post(route('logout'));

    $this->assertSignedOut($response);
    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId)
        ->and(session()->token())->not->toBe($token)
        ->and(session()->has('app-data'))->toBeFalse()
        ->and(session('keystone.status'))->toBe('signed-out');
});

it('sends a guest away', function () {
    $this->assertGuestSentAway($this->post(route('logout')));
    $this->assertGuest();
});

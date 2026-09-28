<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

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

it('throttles the eleventh sign-out in a minute', function () {
    foreach (range(1, 10) as $ignored) {
        $this->post(route('logout'));
    }

    $this->assertSignOutThrottled($this->post(route('logout')));
});

it('records the sign-out on the account\'s trail', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => $support->type()]), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);

    $this->assertSignedOut($this->post(route('logout')));

    $this->assertDatabaseHas('user_security_events', ['type' => 'signed_out', 'user_id' => $account->getKey(), 'actor' => 'user']);
});

it('signs out as usual when recording fails', function () {
    Exceptions::fake();
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createAccount();
    $this->arrangeCredential($account, $support, Surface::SIGN_IN);
    $this->post(route('login.submit', ['type' => $support->type()]), ['identifier' => 'jane@example.com', ...$support->validProof(Surface::SIGN_IN)]);
    Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));

    $this->assertSignedOut($this->post(route('logout')));

    $this->assertGuest();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Listener broke.');
});

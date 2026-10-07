<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvent;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SudoAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
});

it('records the sudo a sign-in brings on the account\'s trail', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.granted', 'user_id' => $account->getKey(), 'reason' => 'keystone.sign_in']);
});

it('ends sudo, keeping the user signed in on a new session id', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $sessionId = session()->getId();

    $response = $this->delete(route('sudo.end'));

    $this->assertSudoEnded($response);
    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.revoked', 'user_id' => $account->getKey(), 'actor' => 'user']);
    expect(session()->getId())->not->toBe($sessionId)
        ->and(session('keystone.status'))->toBe('sudo-revoked');
});

it('records nothing when sudo already ended', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->delete(route('sudo.end'));

    $response = $this->delete(route('sudo.end'));

    $this->assertSudoEnded($response);
    $this->assertAuthenticatedAs($account);
    expect(SecurityEvent::query()->where('type', 'sudo.revoked')->count())->toBe(1);
});

it('sends a guest away from ending sudo', function () {
    $this->assertGuestSentAway($this->delete(route('sudo.end')));
    $this->assertGuest();
});

it('throttles ending sudo past the minute\'s allowance', function () {
    foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
        $this->delete(route('sudo.end'));
    }

    $this->assertSudoThrottled($this->delete(route('sudo.end')));
});

it('puts the hardening floor on the end-sudo response', function () {
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $this->assertHardeningFloor($this->delete(route('sudo.end')));
});

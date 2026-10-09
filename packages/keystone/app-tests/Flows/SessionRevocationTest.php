<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SecurityAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SessionRevocationAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(SecurityAssertions::class),
    AppTestCase::assertions(SessionRevocationAssertions::class),
    AppTestCase::assertions(SudoAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
    $this->useSessionDriver('database');
});

it('names the other session, then signs it out and keeps this one signed in', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $phone = $this->inBrowser('phone', function () {
        $this->passFirstFactor();

        return session()->getId();
    });

    $this->assertRevocationPage($this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)])));
    $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)]));

    $this->assertSessionRevoked($response);
    $this->inBrowser('phone', fn () => $this->assertGuestSentAwayFromSecurity($this->get(route('security'))));
    $this->assertDatabaseHas('user_security_events', ['type' => 'session.revoked', 'user_id' => $account->getKey()]);
    $this->assertAuthenticatedAs($account);
});

it('keeps this session, which signs out instead', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $handle = $this->handleOfSession(session()->getId());

    $this->assertRevocationRefused($this->get(route('security.sessions.revoke', ['session' => $handle])), __('keystone::messages.current_session'));
    $this->assertRevocationRefused($this->delete(route('security.sessions.revoke.submit', ['session' => $handle])), __('keystone::messages.current_session'));
    $this->assertDatabaseMissing('user_security_events', ['type' => 'session.revoked', 'user_id' => $account->getKey()]);
    $this->assertAuthenticatedAs($account);
});

it('sends the user away from another account\'s session, signing nothing out', function () {
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $this->signInAccount($support);
    $other = $this->createAccount('john@example.com');
    $this->arrangeCredential($other, $support, Surface::SIGN_IN);
    $johns = $this->inBrowser('johns-phone', function () {
        $this->passFirstFactor('john@example.com');

        return session()->getId();
    });

    $this->assertSessionNotFound($this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($johns)])));
    $this->assertSessionNotFound($this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($johns)])));
    $this->assertDatabaseHas((string) config('session.table'), ['id' => $johns]);
});

it('asks for sudo before signing anything out', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $phone = $this->inBrowser('phone', function () {
        $this->passFirstFactor();

        return session()->getId();
    });
    $this->delete(route('sudo.end'));

    $this->assertSudoRequired($this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)])));
    $this->assertDatabaseMissing('user_security_events', ['type' => 'session.revoked', 'user_id' => $account->getKey()]);
});

it('refuses on a session driver that keeps no table of sessions', function () {
    $this->useSessionDriver('file');
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $this->assertSessionsUnavailable($this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession(session()->getId())])));
});

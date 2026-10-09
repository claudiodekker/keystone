<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\OtherSessionsAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SecurityAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\DB;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(OtherSessionsAssertions::class),
    AppTestCase::assertions(SecurityAssertions::class),
    AppTestCase::assertions(SudoAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
});

it('confirms, then signs out the other browser and keeps this one signed in', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->inBrowser('phone', fn () => $this->passFirstFactor());

    $this->assertSignOutOthersPage($this->get(route('security.sessions.others.revoke')));
    $response = $this->delete(route('security.sessions.others.revoke.submit'));

    $this->assertOtherSessionsRevoked($response);
    $this->inBrowser('phone', fn () => $this->assertGuestSentAwayFromSecurity($this->get(route('security'))));
    $this->assertDatabaseHas('user_security_events', ['type' => 'sessions.revoked_others', 'user_id' => $account->getKey()]);
    $this->assertAuthenticatedAs($account);
});

it('deletes the other sessions\' rows on the database driver, keeping this session\'s', function () {
    $this->useSessionDriver('database');
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->inBrowser('phone', fn () => $this->passFirstFactor());

    $this->assertOtherSessionsRevoked($this->delete(route('security.sessions.others.revoke.submit')));

    expect(DB::table((string) config('session.table'))->where('user_id', $account->getKey())->pluck('id')->all())->toBe([session()->getId()]);
    $this->assertAuthenticatedAs($account);
});

it('asks for sudo before signing anything out', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $this->inBrowser('phone', fn () => $this->passFirstFactor());
    $this->delete(route('sudo.end'));

    $this->assertSudoRequired($this->delete(route('security.sessions.others.revoke.submit')));
    $this->assertDatabaseMissing('user_security_events', ['type' => 'sessions.revoked_others', 'user_id' => $account->getKey()]);
});

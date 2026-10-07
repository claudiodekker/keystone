<?php

use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;

pest()->extend(StubsTestCase::class)->use(SudoAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
});

it('sends the user whose sudo ended to the root when they came from no page', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->delete(route('sudo.end'));

    $this->assertSudoEnded($response);
    expect(session('keystone.status'))->toBe('sudo-revoked');
});

it('sends the user whose sudo ended back to the page they ended it from', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->from('/settings/security')->delete(route('sudo.end'));

    $response->assertRedirect('/settings/security');
});

it('sends a guest to the sign-in page', function () {
    $this->assertGuestSentAway($this->delete(route('sudo.end')));
});

<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SessionRevocationAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(SessionRevocationAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
    $this->useSessionDriver('database');
});

/**
 * Sign the account in from the named browser and get the id of the session it got.
 */
function signInOtherBrowser(StubsTestCase $test, string $browser): string
{
    return $test->inBrowser($browser, function () use ($test) {
        $test->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
        $test->passFirstFactor();

        return session()->getId();
    });
}

it('lists the sessions on the security page', function () {
    $this->freezeSecond();
    $this->signInAccount(new PasswordTypeSupport);
    $phone = signInOtherBrowser($this, 'phone');

    $response = $this->get(route('security'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('sessionsStatus', null)
        ->has('sessions', 2)
        ->where('sessions.0.current', true)
        ->where('sessions.1.handle', $this->handleOfSession($phone))
        ->where('sessions.1.ipAddress', '198.51.100.7')
        ->where('sessions.1.lastActiveAt', now()->toIso8601String())
        ->where('sessions.1.current', false));
});

it('says on the security page that a driver keeping no table can\'t list the sessions', function () {
    $this->useSessionDriver('file');
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get(route('security'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('sessions', [])
        ->where('sessionsStatus', __('keystone::messages.status.sessions-unavailable')));
});

it('renders the confirm step with the page value\'s fields', function () {
    $this->freezeSecond();
    $this->signInAccount(new PasswordTypeSupport);
    $phone = signInOtherBrowser($this, 'phone');

    $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/SessionRevocation')
        ->where('handle', $this->handleOfSession($phone))
        ->where('ipAddress', '198.51.100.7')
        ->where('lastActiveAt', now()->toIso8601String())
        ->where('current', false)
        ->has('platform')
        ->has('browser')
        ->has('location'));
});

it('encrypts the confirm step in the browser\'s history', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $phone = signInOtherBrowser($this, 'phone');

    $response = $this->get(route('security.sessions.revoke', ['session' => $this->handleOfSession($phone)]));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('shows the sign-out\'s status on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $phone = signInOtherBrowser($this, 'phone');

    $this->assertSessionRevoked($this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession($phone)])));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.session-revoked'))
        ->has('sessions', 1));
});

it('shows the refusal to sign out this session on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->delete(route('security.sessions.revoke.submit', ['session' => $this->handleOfSession(session()->getId())]));

    $this->assertRevocationRefused($response, __('keystone::messages.current_session'));
    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('errors.session', __('keystone::messages.current_session')));
});

it('shows the status of a session the account doesn\'t have on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $this->assertSessionNotFound($this->get(route('security.sessions.revoke', ['session' => str_repeat('a', 64)])));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.session-not-found')));
});

it('shows the status of a driver keeping no table of sessions on the security page', function () {
    $this->useSessionDriver('file');
    $this->signInAccount(new PasswordTypeSupport);

    $this->assertSessionsUnavailable($this->get(route('security.sessions.revoke', ['session' => str_repeat('a', 64)])));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.sessions-unavailable')));
});

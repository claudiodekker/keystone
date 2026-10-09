<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\OtherSessionsAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(OtherSessionsAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
});

it('renders the confirm step', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $this->assertSignOutOthersPage($this->get(route('security.sessions.others.revoke')));
});

it('shows the sign-out\'s status on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $this->assertOtherSessionsRevoked($this->delete(route('security.sessions.others.revoke.submit')));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.other-sessions-revoked'))
        ->where('offersSignOutOthers', false));
});

it('offers to sign out the other sessions on the security page after an enrollment', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.enrolled'))
        ->where('offersSignOutOthers', true));
});

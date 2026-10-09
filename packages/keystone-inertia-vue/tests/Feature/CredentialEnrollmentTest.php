<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\CredentialEnrollmentAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(CredentialEnrollmentAssertions::class);

beforeEach(function () {
    $this->freezeSecond();
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
});

it('renders the type\'s enrollment form with the page value\'s fields', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get(route('security.enroll', ['type' => 'totp']));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/CredentialEnrollment')
        ->where('type', 'totp')
        ->where('shape', 'form')
        ->where('ceremony.key', fn (string $key) => strlen($key) === 32)
        ->where('ceremony.uri', fn (string $uri) => str_starts_with($uri, 'otpauth://totp/'))
        ->where('ceremony.qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,'))
        ->where('status', null));
});

it('encrypts the enrollment form in the browser\'s history', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->get(route('security.enroll', ['type' => 'totp']));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('shows the enrolled status on the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $this->assertCredentialEnrolled($response);
    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('status', __('keystone::messages.status.enrolled'))
        ->where('types.1.credentials', fn ($credentials) => count($credentials) === 1));
});

it('sends a refused answer back to the form with the message on the type', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($this->enrollmentCeremony('totp')));

    $this->assertCredentialEnrollmentRefused($response, 'totp');
    $this->assertCredentialEnrollmentForm($this->get(route('security.enroll', ['type' => 'totp'])), 'totp');
});

it('sends an answer whose ceremony expired back to the form, which says so', function () {
    $this->signInAccount(new PasswordTypeSupport);

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => '123456']);

    $this->assertCredentialEnrollmentExpired($response, 'totp');
    $this->assertCredentialEnrollmentRestarted($this->get(route('security.enroll', ['type' => 'totp'])), 'totp');
});

it('sends a cancelled enrollment to the security page', function () {
    $this->signInAccount(new PasswordTypeSupport);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $response = $this->delete(route('security.enroll.cancel', ['type' => 'totp']));

    $this->assertCredentialEnrollmentCancelled($response);
    expect($this->enrollmentCeremony('totp'))->toBeNull();
});

it('tells the security page which types the user can set up', function () {
    config(['keystone.methods' => ['password' => ['sign-in'], 'totp']]);
    $this->signInAccount(new PasswordTypeSupport);

    $this->get(route('security'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Security')
        ->where('types.0.type', 'password')
        ->where('types.0.enrollable', false)
        ->where('types.1.type', 'totp')
        ->where('types.1.enrollable', true));
});

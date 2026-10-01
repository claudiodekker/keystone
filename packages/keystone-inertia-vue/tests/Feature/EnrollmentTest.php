<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RecoveryCodesAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(EnrollmentAssertions::class, RecoveryCodesAssertions::class);

beforeEach(function () {
    $this->withMandates();
    config(['keystone.methods' => ['form', 'totp']]);
    $this->createFirstFactorAccount();
    $this->passFirstFactor();
});

it('renders the enrollment page with the page value\'s fields', function () {
    $response = $this->get(route('login.enrollment'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Enrollment')
        ->where('types', [['type' => 'totp', 'shape' => 'form']])
        ->where('preselect', 'totp')
        ->where('origin', 'login'));
});

it('renders the type\'s enrollment form with what its ceremony shows, encrypted in the browser\'s history', function () {
    $response = $this->get(route('login.enrollment.start', ['type' => 'totp']));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/EnrollmentForm')
        ->where('type', 'totp')
        ->where('shape', 'form')
        ->has('ceremony.key')
        ->has('ceremony.uri')
        ->where('status', null));
    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('sends a refused answer back to the form with the message on the type', function () {
    $this->get(route('login.enrollment.start', ['type' => 'totp']));

    $response = $this->post(route('login.enrollment.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($this->enrollmentCeremony('totp')));

    $this->assertEnrollmentRefused($response, 'totp');
});

it('sends an enrolled account on to its recovery codes, and shows the staged set', function () {
    $response = $this->enrollSecondFactor(new TotpTypeSupport);

    $this->assertRecoveryCodesOwed($response);
    $page = $this->get(route('login.recovery-codes'));
    $this->assertRecoveryCodesPage($page, $this->stagedRecoveryCodes());
});

it('signs in once a staged code is typed back', function () {
    $this->enrollSecondFactor(new TotpTypeSupport);
    $this->get(route('login.recovery-codes'));

    $response = $this->post(route('login.recovery-codes.submit'), ['code' => $this->stagedRecoveryCodes()[0]]);

    $this->assertRecoveryCodesSaved($response, '/');
});

it('sends a cancelled enrollment to the sign-in page', function () {
    $this->assertEnrollmentCancelled($this->delete(route('login.enrollment.cancel')));
});

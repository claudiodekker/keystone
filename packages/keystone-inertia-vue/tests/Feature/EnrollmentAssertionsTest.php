<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RecoveryCodesAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(EnrollmentAssertions::class, RecoveryCodesAssertions::class);

beforeEach(function () {
    $types = [['type' => 'totp', 'shape' => 'form'], ['type' => 'code', 'shape' => 'form']];
    $form = ['type' => 'totp', 'shape' => 'form', 'ceremony' => []];

    Route::middleware('web')->group(function () use ($types, $form) {
        Route::get('enrollment-page', fn () => Inertia::render('auth/Enrollment', ['types' => $types, 'preselect' => 'totp', 'origin' => 'login']));
        Route::get('other-preselect', fn () => Inertia::render('auth/Enrollment', ['types' => $types, 'preselect' => 'code', 'origin' => 'login']));
        Route::get('enrollment-form', fn () => Inertia::render('auth/EnrollmentForm', [...$form, 'status' => null]));
        Route::get('restarted-form', fn () => Inertia::render('auth/EnrollmentForm', [...$form, 'status' => __('keystone::messages.status.enrollment-expired')]));
        Route::get('recovery-codes', fn () => Inertia::render('auth/RecoveryCodes', ['codes' => ['AAAAA-AAAAA', 'BBBBB-BBBBB']]));
        Route::get('other-component', fn () => Inertia::render('auth/Challenge', ['types' => $types, 'preselect' => 'totp']));
    });
});

it('passes for the page each assertion names', function () {
    $this->assertEnrollmentPage($this->get('enrollment-page'), ['totp', 'code']);
    $this->assertEnrollmentForm($this->get('enrollment-form'), 'totp');
    $this->assertEnrollmentRestarted($this->get('restarted-form'), 'totp');
    $this->assertRecoveryCodesPage($this->get('recovery-codes'), ['AAAAA-AAAAA', 'BBBBB-BBBBB']);
});

it('fails for anything else', function (Closure $assert) {
    expect(fn () => $assert->call($this))->toThrow(AssertionFailedError::class);
})->with([
    'another component for the offer' => fn () => fn () => $this->assertEnrollmentPage($this->get('other-component'), ['totp', 'code']),
    'another order' => fn () => fn () => $this->assertEnrollmentPage($this->get('enrollment-page'), ['code', 'totp']),
    'another preselect' => fn () => fn () => $this->assertEnrollmentPage($this->get('other-preselect'), ['totp', 'code']),
    'another type\'s form' => fn () => fn () => $this->assertEnrollmentForm($this->get('enrollment-form'), 'code'),
    'a form without the expiry' => fn () => fn () => $this->assertEnrollmentRestarted($this->get('enrollment-form'), 'totp'),
    'other codes' => fn () => fn () => $this->assertRecoveryCodesPage($this->get('recovery-codes'), ['AAAAA-AAAAA']),
]);

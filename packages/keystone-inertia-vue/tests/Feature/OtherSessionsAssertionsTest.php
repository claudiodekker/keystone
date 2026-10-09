<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\OtherSessionsAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(OtherSessionsAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('confirm-step', fn () => Inertia::render('settings/SignOutOthers'));
        Route::get('other-component', fn () => Inertia::render('settings/CredentialRemoval'));
    });
});

it('passes for the confirm step', function () {
    $this->assertSignOutOthersPage($this->get('confirm-step'));
});

it('fails for another component', function () {
    expect(fn () => $this->assertSignOutOthersPage($this->get('other-component')))->toThrow(AssertionFailedError::class);
});

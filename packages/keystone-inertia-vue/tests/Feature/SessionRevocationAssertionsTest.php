<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SessionRevocationAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(SessionRevocationAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('confirm-step', fn () => Inertia::render('settings/SessionRevocation'));
        Route::get('other-component', fn () => Inertia::render('settings/SignOutOthers'));
    });
});

it('passes for the confirm step', function () {
    $this->assertRevocationPage($this->get('confirm-step'));
});

it('fails for another component', function () {
    expect(fn () => $this->assertRevocationPage($this->get('other-component')))->toThrow(AssertionFailedError::class);
});

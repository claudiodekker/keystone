<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\RecoveryCodeRegenerationAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(RecoveryCodeRegenerationAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('regenerate', fn () => Inertia::render('settings/RegenerateRecoveryCodes', ['codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'], 'replaces' => true, 'status' => null]));
        Route::get('sign-in-codes', fn () => Inertia::render('auth/RecoveryCodes', ['codes' => ['aaaaa-bbbbb', 'ccccc-ddddd']]));
    });
});

it('passes for the page the assertion names', function () {
    $this->assertRecoveryCodeRegenerationPage($this->get('regenerate'), ['aaaaa-bbbbb', 'ccccc-ddddd']);
});

it('fails for anything else', function (string $uri, array $codes) {
    expect(fn () => $this->assertRecoveryCodeRegenerationPage($this->get($uri), $codes))->toThrow(AssertionFailedError::class);
})->with([
    'another set of codes' => ['regenerate', ['eeeee-fffff', 'ggggg-hhhhh']],
    'the page a sign-in saves its codes at' => ['sign-in-codes', ['aaaaa-bbbbb', 'ccccc-ddddd']],
]);

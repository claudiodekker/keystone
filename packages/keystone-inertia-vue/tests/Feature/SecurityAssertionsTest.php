<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SecurityAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(SecurityAssertions::class);

beforeEach(function () {
    $credential = fn (string $label) => ['id' => 1, 'label' => $label, 'addedAt' => null, 'lastUsedAt' => null, 'disabled' => false];
    $page = [
        'types' => [['type' => 'password', 'credentials' => [$credential('Laptop')]], ['type' => 'totp', 'credentials' => [$credential('Phone')]]],
        'leftovers' => [['type' => 'uninstalled', ...$credential('Old key')]],
        'recoveryCodes' => 8,
        'recoveryCodesLow' => false,
        'sudoEndsAt' => null,
        'status' => null,
    ];

    Route::middleware('web')->group(function () use ($page) {
        Route::get('security-page', fn () => Inertia::render('settings/Security', $page));
        Route::get('other-component', fn () => Inertia::render('auth/Sudo', $page));
    });
});

it('passes for the security page listing exactly the labels in order', function () {
    $this->assertSecurityPage($this->get('security-page'), ['Laptop', 'Phone', 'Old key']);
});

it('passes for the security page listing no credential with the label', function () {
    $this->assertSecurityPageOmits($this->get('security-page'), 'Spare');
});

it('fails for a security page listing the label it omits', function () {
    expect(fn () => $this->assertSecurityPageOmits($this->get('security-page'), 'Old key'))->toThrow(AssertionFailedError::class);
});

it('fails for anything else', function (string $uri, array $labels) {
    expect(fn () => $this->assertSecurityPage($this->get($uri), $labels))->toThrow(AssertionFailedError::class);
})->with([
    'another component' => ['other-component', ['Laptop', 'Phone', 'Old key']],
    'a label too few' => ['security-page', ['Laptop', 'Phone']],
    'a label too many' => ['security-page', ['Laptop', 'Phone', 'Old key', 'Spare']],
    'another order' => ['security-page', ['Phone', 'Laptop', 'Old key']],
]);

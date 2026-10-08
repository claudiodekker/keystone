<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\CredentialRemovalAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(CredentialRemovalAssertions::class);

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('labelled', fn () => Inertia::render('settings/CredentialRemoval', ['id' => 1, 'type' => 'totp', 'label' => 'Phone', 'listed' => true]));
        Route::get('unlabelled', fn () => Inertia::render('settings/CredentialRemoval', ['id' => 1, 'type' => 'totp', 'label' => null, 'listed' => true]));
        Route::get('other-component', fn () => Inertia::render('auth/Sudo', ['id' => 1, 'type' => 'totp', 'label' => 'Phone', 'listed' => true]));
    });
});

it('passes for the confirm step naming the credential by its label, or its type without one', function (string $uri, string $name) {
    $this->assertRemovalPage($this->get($uri), $name);
})->with([
    'a label' => ['labelled', 'Phone'],
    'no label' => ['unlabelled', 'totp'],
]);

it('fails for anything else', function (string $uri, string $name) {
    expect(fn () => $this->assertRemovalPage($this->get($uri), $name))->toThrow(AssertionFailedError::class);
})->with([
    'another component' => ['other-component', 'Phone'],
    'another credential' => ['labelled', 'Laptop'],
    'the type of a labelled credential' => ['labelled', 'totp'],
]);

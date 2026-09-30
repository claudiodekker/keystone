<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(StubsTestCase::class)->use(ChallengeAssertions::class);

beforeEach(function () {
    $types = [['type' => 'totp', 'shape' => 'form'], ['type' => 'code', 'shape' => 'form']];

    Route::middleware('web')->group(function () use ($types) {
        Route::get('challenge-page', fn () => Inertia::render('auth/Challenge', ['types' => $types, 'preselect' => 'totp']));
        Route::get('other-component', fn () => Inertia::render('auth/Other', ['types' => $types, 'preselect' => 'totp']));
        Route::get('other-preselect', fn () => Inertia::render('auth/Challenge', ['types' => $types, 'preselect' => 'code']));
    });
});

it('passes for the challenge page offering the types in order', function () {
    $this->assertChallengePage($this->get('challenge-page'), ['totp', 'code']);
});

it('fails for anything else', function (string $uri, array $types) {
    expect(fn () => $this->assertChallengePage($this->get($uri), $types))->toThrow(AssertionFailedError::class);
})->with([
    'another component' => ['other-component', ['totp', 'code']],
    'other types' => ['challenge-page', ['totp']],
    'another order' => ['challenge-page', ['code', 'totp']],
    'another preselect' => ['other-preselect', ['totp', 'code']],
]);

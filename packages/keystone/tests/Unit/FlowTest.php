<?php

use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\User;

it('derives the sign-in flow for a guest on the sign-in surface', function () {
    expect(Flow::of(Keystone::guard(), Surface::SIGN_IN))->toBe(Flow::SIGN_IN);
});

it('derives no flow for a signed-in user on the sign-in surface', function () {
    Keystone::guard()->setUser(User::factory()->create());

    Flow::of(Keystone::guard(), Surface::SIGN_IN);
})->throws(LogicException::class);

it('derives the challenge flow for a sign-in held at the challenge', function () {
    Keystone::guard()->hold(User::factory()->create(), firstFactor: 'form', stage: PendingStage::CHALLENGE, intendedUrl: '/');

    expect(Flow::of(Keystone::guard(), Surface::CHALLENGE))->toBe(Flow::CHALLENGE);
});

it('derives no flow for the challenge surface without a held sign-in', function () {
    Flow::of(Keystone::guard(), Surface::CHALLENGE);
})->throws(LogicException::class);

it('derives no flow for a surface no flow uses yet', function (Surface $surface) {
    Flow::of(Keystone::guard(), $surface);
})->throws(LogicException::class)->with([Surface::REGISTRATION, Surface::ENROLLMENT]);

it('derives the settings flow for a signed-in session on the enrollment surface', function () {
    Keystone::guard()->setUser(User::factory()->create());

    expect(Flow::of(Keystone::guard(), Surface::ENROLLMENT))->toBe(Flow::SETTINGS);
});

it('keeps the settings flow apart from the counts a first factor guards', function () {
    expect(Flow::SETTINGS->sharesFailedAttempts())->toBeFalse()
        ->and(Flow::SETTINGS->rejectionType())->toBe(SecurityEventType::PROOF_REJECTED);
});

it('derives the sudo flow for a signed-in session owing its first step on the sign-in surface', function () {
    Keystone::guard()->setUser(User::factory()->create());
    Keystone::guard()->beginSudo('/settings');

    expect(Flow::of(Keystone::guard(), Surface::SIGN_IN))->toBe(Flow::SUDO);
});

it('derives the sudo flow for a signed-in session owing its challenge on the challenge surface', function () {
    Keystone::guard()->setUser(User::factory()->create());
    Keystone::guard()->beginSudo('/settings');
    Keystone::guard()->passSudoFirstFactor(Keystone::guard()->sudoInProgress(), 'form');

    expect(Flow::of(Keystone::guard(), Surface::CHALLENGE))->toBe(Flow::SUDO);
});

it('derives no flow for the surface the sudo-in-progress is not at', function (Surface $surface, ?string $firstFactor) {
    Keystone::guard()->setUser(User::factory()->create());
    Keystone::guard()->beginSudo('/settings');

    if ($firstFactor !== null) {
        Keystone::guard()->passSudoFirstFactor(Keystone::guard()->sudoInProgress(), $firstFactor);
    }

    Flow::of(Keystone::guard(), $surface);
})->throws(LogicException::class)->with([
    'the challenge surface while the first step is owed' => [Surface::CHALLENGE, null],
    'the sign-in surface once the first factor passed' => [Surface::SIGN_IN, 'form'],
]);

it('derives no flow for a signed-in session nothing was demanded of', function (Surface $surface) {
    Keystone::guard()->setUser(User::factory()->create());

    Flow::of(Keystone::guard(), $surface);
})->throws(LogicException::class)->with([Surface::SIGN_IN, Surface::CHALLENGE]);

it('derives no sudo flow for a guest holding a sudo-in-progress', function () {
    Keystone::guard()->beginSudo('/settings');

    Flow::of(Keystone::guard(), Surface::CHALLENGE);
})->throws(LogicException::class);

test('only the flows behind a first factor share a guessable type\'s failures', function (Flow $flow, bool $shares) {
    expect($flow->sharesFailedAttempts())->toBe($shares);
})->with([
    'sign-in' => [Flow::SIGN_IN, false],
    'challenge' => [Flow::CHALLENGE, true],
    'enrollment' => [Flow::ENROLLMENT, false],
    'sudo' => [Flow::SUDO, true],
]);

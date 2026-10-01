<?php

use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\PendingStage;
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

it('derives the enrollment flow for a sign-in held at enrollment', function () {
    Keystone::guard()->hold(User::factory()->create(), firstFactor: 'form', stage: PendingStage::ENROLLMENT, intendedUrl: '/');

    expect(Flow::of(Keystone::guard(), Surface::ENROLLMENT))->toBe(Flow::ENROLLMENT);
});

it('derives no flow for the enrollment surface without a sign-in held at enrollment', function () {
    Keystone::guard()->hold(User::factory()->create(), firstFactor: 'form', stage: PendingStage::CHALLENGE, intendedUrl: '/');

    Flow::of(Keystone::guard(), Surface::ENROLLMENT);
})->throws(LogicException::class);

it('derives no flow for a surface no flow uses yet', function () {
    Flow::of(Keystone::guard(), Surface::REGISTRATION);
})->throws(LogicException::class);

test('only the challenge flow shares a guessable type\'s failures', function (Flow $flow, bool $shares) {
    expect($flow->sharesFailedAttempts())->toBe($shares);
})->with([
    'sign-in' => [Flow::SIGN_IN, false],
    'challenge' => [Flow::CHALLENGE, true],
    'enrollment' => [Flow::ENROLLMENT, false],
]);

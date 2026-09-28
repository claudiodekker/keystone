<?php

use ClaudioDekker\Keystone\Flow;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\User;

it('derives the sign-in flow for a guest on the sign-in surface', function () {
    expect(Flow::of(Keystone::guard(), Surface::SIGN_IN))->toBe(Flow::SIGN_IN);
});

it('derives no flow for a signed-in user on the sign-in surface', function () {
    Keystone::guard()->setUser(User::factory()->create());

    Flow::of(Keystone::guard(), Surface::SIGN_IN);
})->throws(LogicException::class);

it('derives no flow for a surface no flow uses yet', function (Surface $surface) {
    Flow::of(Keystone::guard(), $surface);
})->throws(LogicException::class)->with([Surface::CHALLENGE, Surface::REGISTRATION, Surface::ENROLLMENT]);

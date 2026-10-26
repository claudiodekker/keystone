<?php

it('serves a page to a signed-in user', function () {
    $response = $this->actingAs($this->createUser())->get($this->protectedPage());

    expect($response->getStatusCode())->toBe(204);
});

it('turns a guest away without saying a session expired', function () {
    $response = $this->getJson($this->protectedPage());

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('reason'))->toBeNull();
});

it('reads the absolute lifetime from the app', function () {
    config(['keystone.session.absolute_lifetime_seconds' => 8 * 60 * 60]);

    expect($this->absoluteLifetimeSeconds())->toBe(28800);
});

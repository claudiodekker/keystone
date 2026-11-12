<?php

it('turns away a signed-in user without sudo from the gated page', function () {
    $response = $this->actingAs($this->createUser())->getJson($this->sudoGatedPage());

    expect($response->getStatusCode())->toBe(403);
});

it('turns a guest away from the gated page', function () {
    $response = $this->getJson($this->sudoGatedPage());

    expect($response->getStatusCode())->toBe(401);
});

it('reads the sudo lifetime from the app', function () {
    config(['keystone.sudo.lifetime_seconds' => 7 * 60]);

    expect($this->sudoLifetimeSeconds())->toBe(420);
});

it('signs the user in with a sudo grant that already ran out', function () {
    $this->withoutEnrollmentMandates();
    $user = $this->createUser();

    $this->signInWithoutSudo($user);

    $protected = $this->getJson($this->protectedPage());
    $gated = $this->getJson($this->sudoGatedPage());

    expect($protected->getStatusCode())->toBe(204)
        ->and($gated->getStatusCode())->toBe(403);
});

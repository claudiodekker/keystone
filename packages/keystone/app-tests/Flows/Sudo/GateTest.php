<?php

use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('lets a session that just signed in through a gated page', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    Auth::forgetGuards();
    $response = $this->get($this->sudoGatedPage());

    expect($response->getStatusCode())->toBe(204);
});

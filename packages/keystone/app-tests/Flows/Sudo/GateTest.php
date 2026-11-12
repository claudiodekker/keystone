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

it('sends a session without sudo to the sudo page', function () {
    $response = $this->actingAs($this->createUser())->get($this->sudoGatedPage());

    $this->assertSudoRequired($response);
});

it('tells a JSON client without sudo that the page needs sudo', function () {
    $response = $this->actingAs($this->createUser())->getJson($this->sudoGatedPage());

    $this->assertSudoRequiredForJson($response);
});

it("puts the hardening floor on a refusal on the app's own route, for a browser and for JSON", function () {
    $this->actingAs($this->createUser());

    $browser = $this->get($this->sudoGatedPage());
    $json = $this->getJson($this->sudoGatedPage());

    $this->assertHardened($browser);
    $this->assertHardened($json);
});

it("leaves the app's own page unhardened once the gate lets it through", function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);

    Auth::forgetGuards();
    $response = $this->get($this->sudoGatedPage());

    expect($response->headers->get('X-Frame-Options'))->toBeNull();
});

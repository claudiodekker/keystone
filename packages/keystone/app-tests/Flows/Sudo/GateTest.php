<?php

use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

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

it('revokes a live grant used from another network, recording it and alerting the owner, and refuses the request', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $gated = $this->sudoGatedPage();

    Auth::forgetGuards();
    $response = $this->fromAnotherNetwork()->get($gated);

    $changed = $this->securityEvents()->for($user)->last();

    $this->assertSudoRequired($response);
    expect($changed->type)->toBe(SecurityEventType::SUDO_NETWORK_CHANGED)
        ->and($this->sentAlerts()->pluck('alert.type')->all())->toContain(SecurityEventType::SUDO_NETWORK_CHANGED);
});

it('refuses a grant that ran out when used from another network, recording nothing', function () {
    $user = $this->createUser();
    $this->signInWithoutSudo($user);
    $recorded = $this->securityEvents()->for($user)->count();

    $response = $this->fromAnotherNetwork()->get($this->sudoGatedPage());

    $this->assertSudoRequired($response);
    expect($this->securityEvents()->for($user))->toHaveCount($recorded);
});

it('keeps a grant revoked on another network ended once the browser is back on its own', function () {
    $user = $this->createUser();
    $this->passFirstFactor($user);
    $gated = $this->sudoGatedPage();

    Auth::forgetGuards();
    $this->fromAnotherNetwork()->get($gated);

    Auth::forgetGuards();
    $response = $this->fromOwnNetwork()->getJson($gated);

    $this->assertSudoRequiredForJson($response);
});

it('lets a live grant through from another address on the same network', function () {
    $user = $this->createUser();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->passFirstFactor($user);

    Auth::forgetGuards();
    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->get($this->sudoGatedPage());

    expect($response->getStatusCode())->toBe(204);
});

it('turns a guest away on a route gated by sudo alone', function () {
    Route::middleware(['web', 'sudo'])->get('/keystone/app-tests/sudo-only', fn () => response()->noContent());

    $response = $this->getJson('/keystone/app-tests/sudo-only');

    expect($response->getStatusCode())->toBe(401);
});

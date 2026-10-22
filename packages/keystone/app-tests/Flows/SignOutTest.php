<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([Logout::class]);
});

it('signs the user out, and the next request is a guest again', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $response = $this->post(route('logout'));

    $this->assertSignedOut($response);

    $next = $this->get(route('login'));

    $this->assertSignInPage($next, ['status' => __('keystone::messages.signed-out')]);
});

it('ends the whole session, so nothing it held outlives signing out', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $cookie = config('session.cookie');

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $signedIn = $signIn->getCookie($cookie)->getValue();
    $this->withSession(['url.intended' => url('/reports')]);

    $this->withCookie($cookie, $signedIn)->post(route('logout'));

    $this->flushSession()->withCookie($cookie, $signedIn);
    $next = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($next, '/');
});

it("dispatches Laravel's Logout event for the user who signed out", function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->post(route('logout'));

    Event::assertDispatched(Logout::class, fn (Logout $event) => $event->user->is($user));
});

it('records signed_out for the account when the user signs out', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->post(route('logout'));

    $events = $this->securityEvents()->for($user);

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SIGNED_IN, SecurityEventType::SIGNED_OUT])
        ->and($events[1]->flow)->toBeNull();
});

it('records nothing when a guest signs out', function () {
    $this->post(route('logout'));

    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($this->securityEvents()->logged())->toBe([]);
});

it('refuses signing out over the change allowance', function () {
    foreach (range(1, $this->requestAllowance(StepKind::CHANGE)) as $i) {
        $this->post(route('logout'));
    }

    $response = $this->post(route('logout'));

    $this->assertSignOutThrottled($response);
});

it('signs out a guest the same way', function () {
    $response = $this->post(route('logout'));

    $this->assertSignedOut($response);

    $next = $this->get(route('login'));

    $this->assertSignInPage($next, ['status' => __('keystone::messages.signed-out')]);
});

it("clears the browser's cache and storage when the user signs out", function () {
    $user = $this->createUser();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
});

it("clears the browser's cache and storage when a guest signs out too", function () {
    $response = $this->post(route('logout'));

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
});

it('clears what keystone.clear_site_data lists', function () {
    config(['keystone.clear_site_data' => ['executionContexts', 'cookies']]);

    $response = $this->post(route('logout'));

    $this->assertSiteDataCleared($response, ['cookies', 'executionContexts']);
});

it('clears nothing when keystone.clear_site_data is empty', function () {
    config(['keystone.clear_site_data' => []]);

    $response = $this->post(route('logout'));

    $this->assertSiteDataKept($response);
});

it('clears the cache and storage when keystone.clear_site_data is not a list of kinds', function (mixed $value) {
    config(['keystone.clear_site_data' => $value]);

    $response = $this->post(route('logout'));

    $this->assertSiteDataCleared($response, ['cache', 'storage']);
})->with([
    'a string' => 'cookies',
    'a map' => [['kind' => 'cookies']],
    'an unknown kind' => [['cookies', 'everything']],
    'the wildcard' => [['*']],
    'a quoted kind' => [['"cookies"']],
    'a non-string kind' => [[1]],
]);

it('clears nothing when signing out is throttled', function () {
    foreach (range(1, $this->requestAllowance(StepKind::CHANGE)) as $i) {
        $this->post(route('logout'));
    }

    $response = $this->post(route('logout'));

    $this->assertSignOutThrottled($response);
    $this->assertSiteDataKept($response);
});

it('clears nothing when a cross-site sign-out is refused', function () {
    $response = $this->fromAnotherSite()->post(route('logout'));

    $this->assertCrossSiteRefused($response);
    $this->assertSiteDataKept($response);
});

it('clears nothing on the pages a signed-out user then visits', function () {
    $this->post(route('logout'));

    $response = $this->get(route('login'));

    $this->assertSiteDataKept($response);
});

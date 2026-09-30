<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
});

it('hardens every Keystone response, whatever answers the request', function (Closure $request) {
    $response = $request->call($this);

    $this->assertHardeningFloor($response);
})->with([
    'the sign-in page' => fn () => $this->get(route('login')),
    'invalid sign-in input' => fn () => $this->post(route('login.submit', ['type' => $this->support->type()])),
    'a refused sign-in' => fn () => $this->post(route('login.submit', ['type' => $this->support->type()]), ['identifier' => 'nobody@example.com', ...$this->support->rejectedProof(Surface::SIGN_IN)]),
    'a signed-in user sent away from sign-in' => function () {
        $this->signInAccount($this->support);

        return $this->get(route('login'));
    },
    'a sign-out' => function () {
        $this->signInAccount($this->support);

        return $this->post(route('logout'));
    },
    'a guest sent away from sign-out' => fn () => $this->post(route('logout')),
    'a throttled request' => function () {
        foreach (range(1, 60) as $ignored) {
            $this->get(route('login'));
        }

        return $this->get(route('login'));
    },
    'a cross-site refusal' => fn () => $this->withHeader('Sec-Fetch-Site', 'cross-site')->post(route('logout')),
]);

it('refuses a cross-site sign-in', function () {
    $account = $this->createAccount();
    $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

    $this->withHeader('Sec-Fetch-Site', 'cross-site')->post(route('login.submit', ['type' => $this->support->type()]), ['identifier' => 'jane@example.com', ...$this->support->validProof(Surface::SIGN_IN)]);

    $this->assertGuest();
});

it('refuses a cross-site sign-out', function () {
    $account = $this->signInAccount($this->support);

    $this->withHeader('Sec-Fetch-Site', 'cross-site')->post(route('logout'));

    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'request.rejected', 'user_id' => $account->getKey(), 'reason' => 'keystone.cross_site']);
});

it('clears the site data the app chose on sign-out', function () {
    $types = (array) config('keystone.clear_site_data');
    $expected = $types === [] ? null : implode(', ', array_map(fn (string $type) => "\"{$type}\"", $types));
    $this->signInAccount($this->support);

    $response = $this->post(route('logout'));

    expect($response->headers->get('Clear-Site-Data'))->toBe($expected);
});

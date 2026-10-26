<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

it('refuses a cross-site sign-in submit, and records request.rejected', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->fromAnotherSite()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertCrossSiteRefused($response);

    $lines = collect($this->securityEvents()->logged())->where('type', 'request.rejected')->values();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['reason'])->toBe('keystone.cross_site_request')
        ->and($lines[0]['user_id'])->toBeNull()
        ->and($this->securityEvents()->stored())->toBeEmpty();
});

it('signs nobody in when it refuses a cross-site sign-in submit', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->fromAnotherSite()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $next = $this->get(route('login'));

    $this->assertSignInPage($next);
});

it('refuses a cross-site sign-out, and the user stays signed in', function () {
    $user = $this->createUser();

    $response = $this->actingAs($user)->fromAnotherSite()->post(route('logout'));

    $this->assertCrossSiteRefused($response);

    $next = $this->get(route('login'));

    $this->assertAlreadySignedIn($next);
    expect($this->securityEvents()->for($user))->toBeEmpty();
});

it("refuses a cross-site submit even when the app exempts every route from Laravel's CSRF check", function () {
    PreventRequestForgery::except('*');
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->fromAnotherSite()->post(route('login.submit', $support->type()->name()));

    $this->assertCrossSiteRefused($response);
});

it("refuses a cross-site sign-out even when the app removes Laravel's CSRF middleware from the route", function () {
    $user = $this->createUser();
    Route::getRoutes()->getByName('logout')->withoutMiddleware(PreventRequestForgery::class);

    $response = $this->actingAs($user)->fromAnotherSite()->post(route('logout'));

    $this->assertCrossSiteRefused($response);

    $next = $this->get(route('login'));

    $this->assertAlreadySignedIn($next);
});

it('refuses a submit from another subdomain', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withHeader('Sec-Fetch-Site', 'same-site')->post(route('login.submit', $support->type()->name()));

    $this->assertCrossSiteRefused($response);
});

it('refuses a submit that says where it came from in no way', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withoutHeader('Sec-Fetch-Site')->post(route('login.submit', $support->type()->name()));

    $this->assertCrossSiteRefused($response);
});

it("takes a submit from the app's own pages", function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it("takes a submit carrying the session's CSRF token in the _token field", function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->withSession(['_token' => 'a-token-no-one-guesses']);

    $response = $this->withoutHeader('Sec-Fetch-Site')->post(route('login.submit', $support->type()->name()), [
        '_token' => 'a-token-no-one-guesses',
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it("takes a submit carrying the session's CSRF token in the X-CSRF-TOKEN header", function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->withSession(['_token' => 'a-token-no-one-guesses']);

    $response = $this->withoutHeader('Sec-Fetch-Site')
        ->withHeader('X-CSRF-TOKEN', 'a-token-no-one-guesses')
        ->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'ada@example.com',
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('takes a submit carrying the encrypted XSRF-TOKEN cookie value in the X-XSRF-TOKEN header', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $page = $this->get(route('login'));
    $session = $page->getCookie(config('session.cookie'))->getValue();
    $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();

    $response = $this->withoutHeader('Sec-Fetch-Site')
        ->withCookie(config('session.cookie'), $session)
        ->withHeader('X-XSRF-TOKEN', $xsrf)
        ->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'ada@example.com',
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('refuses a submit carrying another CSRF token than the session holds', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->withSession(['_token' => 'a-token-no-one-guesses']);

    $response = $this->fromAnotherSite()->post(route('login.submit', $support->type()->name()), [
        '_token' => 'another-token',
    ]);

    $this->assertCrossSiteRefused($response);
});

it("takes a submit whose Origin is the app's own", function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withoutHeader('Sec-Fetch-Site')
        ->withHeader('Origin', config('app.url'))
        ->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'ada@example.com',
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('lets another site load the sign-in page', function () {
    $response = $this->fromAnotherSite()->get(route('login'));

    $this->assertSignInPage($response);
});

it('takes a submit from an origin in trusted_origins', function () {
    config(['keystone.trusted_origins' => ['https://admin.example.com']]);
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withHeaders(['Origin' => 'https://admin.example.com', 'Sec-Fetch-Site' => 'same-site'])
        ->post(route('login.submit', $support->type()->name()), [
            'identifier' => 'ada@example.com',
            ...$support->rejectedProof(Surface::SIGN_IN),
        ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('refuses a submit from an origin that trusted_origins does not list', function () {
    config(['keystone.trusted_origins' => ['https://admin.example.com']]);
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withHeaders(['Origin' => 'https://billing.example.com', 'Sec-Fetch-Site' => 'same-site'])
        ->post(route('login.submit', $support->type()->name()));

    $this->assertCrossSiteRefused($response);
});

it('trusts no other origin when trusted_origins is not a list of origins', function (mixed $value) {
    config(['keystone.trusted_origins' => $value]);
    $support = $this->supportFor(Surface::SIGN_IN);

    $response = $this->withHeaders(['Origin' => 'https://admin.example.com', 'Sec-Fetch-Site' => 'same-site'])
        ->post(route('login.submit', $support->type()->name()));

    $this->assertCrossSiteRefused($response);
})->with([
    'a string' => 'https://admin.example.com',
    'a map' => [['admin' => 'https://admin.example.com']],
    'a good origin beside one with a path' => [['https://admin.example.com', 'https://admin.example.com/login']],
    'a good origin beside one with no scheme' => [['https://admin.example.com', 'billing.example.com']],
    'a good origin beside a non-string' => [['https://admin.example.com', 1]],
]);

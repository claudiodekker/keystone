<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutEnrollmentMandates();
});

it('switches to a fresh browser that is not signed in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->switchToFreshBrowser();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response, ['status' => null]);
});

it('switches back to an earlier browser, still signed in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $first = $this->switchToFreshBrowser();
    $this->get(route('login'));

    $this->switchBackTo($first);
    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});

it('keeps the cookies a response set, and sends them from here on', function () {
    Route::middleware('web')->get('/keystone/app-tests/cookie', fn (Request $request) => response($request->cookie('flavour') ?? 'none')->cookie('flavour', 'oatmeal'));

    $first = $this->get('/keystone/app-tests/cookie');
    $this->keepCookiesFrom($first);
    $second = $this->get('/keystone/app-tests/cookie');

    expect($first->getContent())->toBe('none')
        ->and($second->getContent())->toBe('oatmeal');
});

it('leaves the cookies behind with the browser that kept them, and brings them back with it', function () {
    Route::middleware('web')->get('/keystone/app-tests/cookie', fn (Request $request) => response($request->cookie('flavour') ?? 'none')->cookie('flavour', 'oatmeal'));
    $this->keepCookiesFrom($this->get('/keystone/app-tests/cookie'));

    $first = $this->switchToFreshBrowser();
    $fresh = $this->get('/keystone/app-tests/cookie');
    $this->switchBackTo($first);
    $back = $this->get('/keystone/app-tests/cookie');

    expect($fresh->getContent())->toBe('none')
        ->and($back->getContent())->toBe('oatmeal');
});

it('copies the cookies of this browser to a fresh one, which holds none of its session', function () {
    Route::middleware('web')->get('/keystone/app-tests/cookie', fn (Request $request) => response($request->cookie('flavour') ?? 'none')->cookie('flavour', 'oatmeal'));
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->keepCookiesFrom($signIn);
    $this->keepCookiesFrom($this->get('/keystone/app-tests/cookie'));

    $this->switchBackTo($this->browserWithCopiedCookies());
    $cookie = $this->get('/keystone/app-tests/cookie');
    $page = $this->get(route('login'));

    expect($cookie->getContent())->toBe('oatmeal');
    $this->assertSignInPage($page, ['status' => null]);
});

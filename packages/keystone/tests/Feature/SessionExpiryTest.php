<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    config(['keystone.session.absolute_lifetime_seconds' => 3600]);
    Route::middleware(['web', 'auth'])->get('dashboard', fn () => 'The dashboard.');
    Route::middleware('web')->get('welcome', fn () => auth()->check() ? 'Welcome back.' : 'Welcome, guest.');
});

it('sends an expired browser to sign in, clearing the site\'s data', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(3600)->seconds();

    $response = $this->get('dashboard');

    $response->assertRedirectToRoute('login')->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

it('says on the sign-in page why the session ended', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(3600)->seconds();
    $this->get('dashboard');

    $response = $this->get(route('login'));

    $response->assertJsonPath('status', __('keystone::messages.status.session-expired'));
});

it('answers an expired JSON request with 401 and the reason', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(3600)->seconds();

    $response = $this->getJson('dashboard');

    $response->assertUnauthorized()
        ->assertExactJson(['message' => __('keystone::messages.status.session-expired'), 'reason' => 'expired'])
        ->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

it('answers a JSON request of a guest the usual way', function () {
    $response = $this->getJson('dashboard');

    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

it('tells a JSON client the app\'s own translation of the expiry', function () {
    $this->app['translator']->addLines(['messages.status.session-expired' => 'Time\'s up.'], 'en', 'keystone');
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(3600)->seconds();

    $response = $this->getJson('dashboard');

    $response->assertJsonPath('message', 'Time\'s up.');
});

it('lets a page open to guests answer an expired browser as a guest', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(3600)->seconds();

    $response = $this->get('welcome');

    $response->assertSee('Welcome, guest.')->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

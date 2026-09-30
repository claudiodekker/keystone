<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Route::middleware(['web', 'auth'])->get('dashboard', fn () => 'The dashboard.');
    Route::middleware('web')->get('welcome', fn () => auth()->check() ? 'Welcome back.' : 'Welcome, guest.');
});

it('sends an expired browser to sign in, where the page says why', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(43200)->seconds();

    $response = $this->get('dashboard');

    $response->assertRedirectToRoute('login')->assertHeader('Clear-Site-Data', '"cache", "storage"');
    $this->get(route('login'))->assertJsonPath('status', 'Your session has expired. Please sign in again.');
});

it('answers an expired JSON request with 401 and the reason', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(43200)->seconds();

    $response = $this->getJson('dashboard');

    $response->assertUnauthorized()
        ->assertExactJson(['message' => 'Your session has expired. Please sign in again.', 'reason' => 'expired'])
        ->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

it('answers a JSON request of a guest the usual way', function () {
    $response = $this->getJson('dashboard');

    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

it('carries the app\'s own translation of the expiry message', function () {
    $this->app['translator']->addLines(['messages.status.session-expired' => 'Time\'s up.'], 'en', 'keystone');
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(43200)->seconds();

    $this->getJson('dashboard')->assertJsonPath('message', 'Time\'s up.');
    $this->get(route('login'))->assertJsonPath('status', 'Time\'s up.');
});

it('lets a page open to guests answer an expired browser as a guest', function () {
    $this->freezeSecond();
    $this->signInAccount(new FormTypeSupport);
    $this->travel(43200)->seconds();

    $response = $this->get('welcome');

    $response->assertSee('Welcome, guest.')->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

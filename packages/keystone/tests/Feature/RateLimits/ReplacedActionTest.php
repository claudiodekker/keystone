<?php

// An app's AppTests run through its own controllers, so only core can arrange one that replaces an action outright.

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\ReplacedSignInController;
use Illuminate\Support\Facades\Route;

it('throttles an app controller that replaces the sign-in actions outright', function () {
    Route::middleware('web')->get('replaced', [ReplacedSignInController::class, 'show']);
    Route::middleware('web')->post('replaced/{type}', [ReplacedSignInController::class, 'store']);

    foreach (range(1, config('keystone.rate_limits.requests_per_minute.view')) as $i) {
        $this->get('replaced')->assertOk();
    }

    foreach (range(1, config('keystone.rate_limits.requests_per_minute.submit')) as $i) {
        $this->post('replaced/form')->assertOk();
    }

    $page = $this->get('replaced');
    $submit = $this->post('replaced/form');

    $page->assertTooManyRequests();
    $submit->assertTooManyRequests();
});

it('hardens an app controller that replaces the sign-in actions outright', function () {
    Route::middleware('web')->get('replaced', [ReplacedSignInController::class, 'show']);

    $response = $this->get('replaced');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
});

it('refuses a cross-site submit to an app controller that replaces the sign-in actions outright', function () {
    Route::middleware('web')->post('replaced/{type}', [ReplacedSignInController::class, 'store']);

    $response = $this->withHeader('Sec-Fetch-Site', 'cross-site')->post('replaced/form');

    $response->assertStatus(419);
});

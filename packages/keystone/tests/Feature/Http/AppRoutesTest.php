<?php

// AppTests register no routes, so only core can serve an app route beside Keystone's.

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\AppPageController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

it('leaves the headers of the routes of the app alone', function (string $path) {
    Route::middleware('web')->get('app/closure', fn () => response('page', headers: ['X-Frame-Options' => 'SAMEORIGIN']));
    Route::middleware('web')->get('app/controller', [AppPageController::class, 'show']);

    $response = $this->get($path);

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->has('Pragma'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
})->with([
    'a closure' => 'app/closure',
    'a controller that is not Keystone\'s' => 'app/controller',
]);

it('runs no cross-site check on the routes of the app', function () {
    Route::middleware('web')->post('app/controller', [AppPageController::class, 'store']);

    $response = $this->withHeader('Sec-Fetch-Site', 'cross-site')->post('app/controller');

    expect($response->getContent())->toBe('stored');
});

it("clears the browser's site data when a route of the app signs the user out", function () {
    Route::middleware('web')->post('app/sign-out', function () {
        Auth::logout();

        return response('out');
    });

    $response = $this->post('app/sign-out');

    expect($response->headers->get('Clear-Site-Data'))->toBe('"cache", "storage"');
});

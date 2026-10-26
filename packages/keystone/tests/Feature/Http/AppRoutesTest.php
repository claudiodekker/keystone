<?php

// AppTests serve only Keystone's routes and one empty page behind auth, so only core can serve an app route beside Keystone's.

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Exceptions\PlainExceptionHandler;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\AppPageController;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
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

it("leaves a handler that does not extend Laravel's to its own unauthenticated response, and still ends and records the session", function () {
    $user = User::factory()->create();
    $fake = $this->app->make(ExceptionHandler::class);
    $this->app->singleton(ExceptionHandler::class, PlainExceptionHandler::class);
    // Resolving it runs Keystone's hook against the plain handler before any request does.
    $this->app->make(ExceptionHandler::class);
    Route::middleware(['web', 'auth'])->get('app/protected', fn () => response()->noContent());

    try {
        $response = $this->withSession([Keystone::guard()->getName() => $user->getAuthIdentifier()])->get('app/protected');
    } finally {
        $this->app->instance(ExceptionHandler::class, $fake);
    }

    $recorded = UserSecurityEvent::query()->where('user_id', $user->getKey())->pluck('type');

    expect($response->getStatusCode())->toBe(418)
        ->and($response->getContent())->toBe('plain handler')
        ->and($response->headers->get('Clear-Site-Data'))->toBe('"cache", "storage"')
        ->and($recorded->all())->toBe([SecurityEventType::SESSION_ENDED]);
});

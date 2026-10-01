<?php

use App\Http\Controllers\Auth\ChallengeController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::prefix('login')->group(function () {
        Route::get('/', [SignInController::class, 'show'])->name('login');
        Route::post('{type}', [SignInController::class, 'store'])->name('login.submit');

        Route::prefix('challenge')->group(function () {
            Route::get('/', [ChallengeController::class, 'show'])->name('login.challenge');
            Route::post('{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
            Route::delete('/', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
        });
    });

    Route::post('logout', SignOutController::class)->name('logout');
});

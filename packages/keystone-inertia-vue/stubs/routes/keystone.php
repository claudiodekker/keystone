<?php

use App\Http\Controllers\Auth\ChallengeController;
use App\Http\Controllers\Auth\EnrollmentController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\Auth\SudoController;
use Illuminate\Support\Facades\Route;

Route::prefix('login')->group(function () {
    Route::get('/', [SignInController::class, 'show'])->name('login');
    Route::post('{type}', [SignInController::class, 'store'])->name('login.submit');

    Route::prefix('challenge')->group(function () {
        Route::get('/', [ChallengeController::class, 'show'])->name('login.challenge');
        Route::post('{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
        Route::delete('/', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
    });

    Route::prefix('enrollment')->group(function () {
        Route::get('/', [EnrollmentController::class, 'show'])->name('login.enrollment');
        Route::post('{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
        Route::delete('/', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');
    });
});

Route::post('logout', [SignOutController::class, 'destroy'])->name('logout');

Route::prefix('sudo')->group(function () {
    Route::get('/', [SudoController::class, 'show'])->name('sudo');
    Route::post('{type}', [SudoController::class, 'store'])->name('sudo.submit');
});

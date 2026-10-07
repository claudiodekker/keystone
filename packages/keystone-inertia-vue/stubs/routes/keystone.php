<?php

use App\Http\Controllers\Auth\ChallengeController;
use App\Http\Controllers\Auth\EnrollmentController;
use App\Http\Controllers\Auth\RecoveryCodesController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\Auth\SudoController;
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

        Route::prefix('enrollment')->group(function () {
            Route::get('/', [EnrollmentController::class, 'show'])->name('login.enrollment');
            Route::get('{type}', [EnrollmentController::class, 'create'])->name('login.enrollment.start');
            Route::post('{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
            Route::delete('/', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');
        });

        Route::prefix('recovery-codes')->group(function () {
            Route::get('/', [RecoveryCodesController::class, 'show'])->name('login.recovery-codes');
            Route::post('confirm', [RecoveryCodesController::class, 'store'])->name('login.recovery-codes.submit');
        });
    });

    Route::post('logout', SignOutController::class)->name('logout');

    Route::delete('sudo', [SudoController::class, 'destroy'])->name('sudo.end');
});

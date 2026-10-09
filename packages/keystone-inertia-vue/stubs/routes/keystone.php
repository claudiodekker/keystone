<?php

use App\Http\Controllers\Auth\ChallengeController;
use App\Http\Controllers\Auth\CredentialEnrollmentController;
use App\Http\Controllers\Auth\CredentialRemovalController;
use App\Http\Controllers\Auth\EnrollmentController;
use App\Http\Controllers\Auth\OtherSessionsController;
use App\Http\Controllers\Auth\RecoveryCodesController;
use App\Http\Controllers\Auth\SecurityController;
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

    Route::prefix('sudo')->group(function () {
        Route::get('/', [SudoController::class, 'show'])->name('sudo');
        Route::post('{type}', [SudoController::class, 'store'])->name('sudo.submit');
        Route::delete('/', [SudoController::class, 'destroy'])->name('sudo.end');
    });
});

Route::prefix('settings/security')->group(function () {
    Route::get('/', [SecurityController::class, 'show'])->name('security');

    Route::prefix('enroll/{type}')->group(function () {
        Route::get('/', [CredentialEnrollmentController::class, 'create'])->name('security.enroll');
        Route::post('/', [CredentialEnrollmentController::class, 'store'])->name('security.enroll.submit');
        Route::delete('/', [CredentialEnrollmentController::class, 'destroy'])->name('security.enroll.cancel');
    });

    Route::get('credentials/{credential}/remove', [CredentialRemovalController::class, 'show'])->name('security.credentials.remove');
    Route::delete('credentials/{credential}', [CredentialRemovalController::class, 'destroy'])->name('security.credentials.remove.submit');

    Route::get('sessions/others/revoke', [OtherSessionsController::class, 'show'])->name('security.sessions.others.revoke');
    Route::delete('sessions/others', [OtherSessionsController::class, 'destroy'])->name('security.sessions.others.revoke.submit');
});

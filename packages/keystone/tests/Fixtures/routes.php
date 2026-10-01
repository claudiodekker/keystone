<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\ChallengeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\EnrollmentController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\RecoveryCodesController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignInController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignOutController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
    Route::get('login/challenge', [ChallengeController::class, 'show'])->name('login.challenge');
    Route::post('login/challenge/{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
    Route::delete('login/challenge', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
    Route::get('login/enrollment', [EnrollmentController::class, 'show'])->name('login.enrollment');
    Route::get('login/enrollment/{type}', [EnrollmentController::class, 'create'])->name('login.enrollment.start');
    Route::post('login/enrollment/{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
    Route::delete('login/enrollment', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');
    Route::get('login/recovery-codes', [RecoveryCodesController::class, 'show'])->name('login.recovery-codes');
    Route::post('login/recovery-codes/confirm', [RecoveryCodesController::class, 'store'])->name('login.recovery-codes.submit');
    Route::post('logout', SignOutController::class)->name('logout');
});

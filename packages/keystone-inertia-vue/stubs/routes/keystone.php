<?php

use App\Http\Controllers\Auth\ChallengeController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
    Route::get('login/challenge', [ChallengeController::class, 'show'])->name('login.challenge');
    Route::post('login/challenge/{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
    Route::delete('login/challenge', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
    Route::post('logout', SignOutController::class)->name('logout');
});

<?php

use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;

Route::prefix('login')->group(function () {
    Route::get('/', [SignInController::class, 'show'])->name('login');
    Route::post('{type}', [SignInController::class, 'store'])->name('login.submit');
});

Route::post('logout', [SignOutController::class, 'destroy'])->name('logout');

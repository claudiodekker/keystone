<?php

use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
    Route::post('logout', SignOutController::class)->name('logout');
});

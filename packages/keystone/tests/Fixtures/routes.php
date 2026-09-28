<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignInController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignOutController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
    Route::post('logout', SignOutController::class)->name('logout');
});

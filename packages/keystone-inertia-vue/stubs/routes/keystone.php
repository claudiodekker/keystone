<?php

use App\Http\Controllers\Keystone\SignInController;
use App\Http\Controllers\Keystone\SignOutController;
use Illuminate\Support\Facades\Route;

Route::get('login', [SignInController::class, 'show'])->name('login');
Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
Route::post('logout', SignOutController::class)->name('logout');

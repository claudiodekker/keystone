<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignInController;
use Illuminate\Support\Facades\Route;

Route::get('login', [SignInController::class, 'show'])->name('login');
Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');

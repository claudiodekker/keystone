<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\ChallengeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\EnrollmentController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignInController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignOutController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SudoController;
use Illuminate\Support\Facades\Route;

Route::get('login', [SignInController::class, 'show'])->name('login');
Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');

Route::get('login/challenge', [ChallengeController::class, 'show'])->name('login.challenge');
Route::post('login/challenge/{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
Route::delete('login/challenge', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');

Route::get('login/enrollment', [EnrollmentController::class, 'show'])->name('login.enrollment');
Route::post('login/enrollment/{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
Route::delete('login/enrollment', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');

Route::post('logout', [SignOutController::class, 'destroy'])->name('logout');

Route::get('sudo', [SudoController::class, 'show'])->name('sudo');
Route::post('sudo/{type}', [SudoController::class, 'store'])->name('sudo.submit');
Route::delete('sudo', [SudoController::class, 'destroy'])->name('sudo.end');

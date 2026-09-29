<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

Route::middleware(['web', Middleware::class])->group(function () {
    require __DIR__.'/../../packages/keystone-inertia-vue/stubs/routes/keystone.php';

    Route::get('/', fn () => Inertia::render('Home', ['signedIn' => Auth::check()]))->name('home');
});

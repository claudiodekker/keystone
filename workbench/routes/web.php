<?php

use ClaudioDekker\Keystone\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

Route::middleware(['web', Middleware::class])->group(function () {
    require __DIR__.'/../../packages/keystone-inertia-vue/stubs/routes/keystone.php';

    Route::get('/', fn (Request $request) => Inertia::render('Home', [
        'signedIn' => Auth::check(),
        'status' => Status::flashed($request)?->label(),
    ]))->name('home');

    Route::middleware(['auth', 'sudo'])->get('settings/security', fn () => Inertia::render('Security'))->name('settings.security');
});

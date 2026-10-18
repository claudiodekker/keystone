<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

require dirname(__DIR__, 2).'/packages/keystone-inertia-vue/stubs/routes/keystone.php';

Route::get('/', function (Request $request) {
    $user = $request->user();

    if ($user === null) {
        return to_route('login');
    }

    // The home page is plain Blade, so an Inertia visit to it becomes a full page load.
    if ($request->inertia()) {
        return Inertia::location($request->fullUrl());
    }

    return response()
        ->view('home', ['user' => $user])
        ->header('Cache-Control', 'no-store');
});

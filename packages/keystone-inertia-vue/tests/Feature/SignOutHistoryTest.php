<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Inertia\Testing\AssertableInertia;

use function Orchestra\Testbench\workbench_path;

beforeEach(function () {
    $this->app->register(InertiaServiceProvider::class);

    config(['inertia.testing.ensure_pages_exist' => false]);

    View::addLocation(workbench_path('resources', 'views'));

    Route::middleware('web')->group(dirname(__DIR__, 2).'/stubs/routes/keystone.php');
});

it('clears the browser history on the first page after signing out', function () {
    $this->post(route('logout'));

    $response = $this->get(route('login'));

    $response->assertInertia(fn (AssertableInertia $page) => expect($page->toArray())->toHaveKey('clearHistory'));
});

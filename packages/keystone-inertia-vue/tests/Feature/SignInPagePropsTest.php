<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SignInAssertions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Inertia\Testing\AssertableInertia;

use function Orchestra\Testbench\workbench_path;

uses(SignInAssertions::class);

beforeEach(function () {
    $this->app->register(InertiaServiceProvider::class);

    config(['inertia.pages.paths' => [dirname(__DIR__, 2).'/stubs/resources/js/pages']]);

    View::addLocation(workbench_path('resources', 'views'));

    Route::middleware('web')->group(dirname(__DIR__, 2).'/stubs/routes/keystone.php');
});

it('passes every field of the sign-in page value to the page as a prop of its own', function () {
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
    $response->assertInertia(fn (AssertableInertia $page) => $page->missing('page'));
});

<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;
use Inertia\Testing\AssertableInertia;

uses(InstallsIntoFreshApp::class);

it('gives a fresh Laravel app a working sign-in page', function () {
    $this->artisan('keystone:install')->assertSuccessful();
    $this->mountFreshApp();

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

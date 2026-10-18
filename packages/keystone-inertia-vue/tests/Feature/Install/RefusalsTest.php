<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

it('refuses while laravel/fortify is required, changing nothing', function () {
    $this->fresh->require('laravel/fortify');
    $before = $this->fresh->fingerprint();

    $this->artisan('keystone:install')
        ->expectsOutputToContain('laravel/fortify')
        ->assertFailed();

    expect($this->fresh->fingerprint())->toBe($before);
});

it('refuses while laravel/fortify is required for development, changing nothing', function () {
    $this->fresh->requireDev('laravel/fortify');
    $before = $this->fresh->fingerprint();

    $this->artisan('keystone:install')
        ->expectsOutputToContain('laravel/fortify')
        ->assertFailed();

    expect($this->fresh->fingerprint())->toBe($before);
});

it('refuses while another package pulls laravel/fortify in, changing nothing', function () {
    $this->fresh->withInstalled('laravel/fortify');
    $before = $this->fresh->fingerprint();

    $this->artisan('keystone:install')
        ->expectsOutputToContain('laravel/fortify')
        ->assertFailed();

    expect($this->fresh->fingerprint())->toBe($before);
});

it('refuses without Pest, changing nothing', function () {
    $this->fresh->remove('pestphp/pest');
    $before = $this->fresh->fingerprint();

    $this->artisan('keystone:install')
        ->expectsOutputToContain('Pest')
        ->assertFailed();

    expect($this->fresh->fingerprint())->toBe($before);
});

it('accepts Pest that only another package pulls in', function () {
    $this->fresh->remove('pestphp/pest')->withInstalled('pestphp/pest');

    $this->artisan('keystone:install')->assertSuccessful();
});

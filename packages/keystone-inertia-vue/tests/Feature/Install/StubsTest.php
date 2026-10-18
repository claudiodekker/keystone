<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

it('copies the stubs into the app as its own files', function (string $path) {
    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->contents($path))->toBe(file_get_contents(app(Stubs::class)->path($path)));
})->with([
    'controller' => ['app/Http/Controllers/Auth/SignInController.php'],
    'route file' => ['routes/keystone.php'],
    'test case' => ['tests/Keystone/TestCase.php'],
]);

it('keeps a file the app already has, and lists it as skipped', function () {
    $this->fresh->put('routes/keystone.php', "<?php // the app's own\n");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('routes/keystone.php')
        ->expectsOutputToContain('--force')
        ->assertSuccessful();

    expect($this->fresh->contents('routes/keystone.php'))->toBe("<?php // the app's own\n")
        ->and($this->fresh->has('app/Http/Controllers/Auth/SignInController.php'))->toBeTrue();
});

it('overwrites a file the app already has with --force', function () {
    $this->fresh->put('routes/keystone.php', "<?php // the app's own\n");

    $this->artisan('keystone:install', ['--force' => true])->assertSuccessful();

    expect($this->fresh->contents('routes/keystone.php'))->toBe(file_get_contents(app(Stubs::class)->path('routes/keystone.php')));
});

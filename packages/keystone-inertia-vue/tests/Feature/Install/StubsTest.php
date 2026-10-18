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

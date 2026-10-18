<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

it('sets Inertia and Vue up in a bare app', function (string $path) {
    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->contents($path))->toBe(file_get_contents(app(Stubs::class)->path($path)));
})->with([
    'middleware' => ['app/Http/Middleware/HandleInertiaRequests.php'],
    'entry' => ['resources/js/app.ts'],
    'root view' => ['resources/views/app.blade.php'],
    'TypeScript config' => ['tsconfig.json'],
    'Vite config' => ['vite.config.ts'],
]);

it('keeps the Vite entry files of a bare app as .bak', function () {
    $viteConfig = $this->fresh->contents('vite.config.js');
    $entry = $this->fresh->contents('resources/js/app.js');

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('vite.config.js'))->toBeFalse()
        ->and($this->fresh->has('resources/js/app.js'))->toBeFalse()
        ->and($this->fresh->contents('vite.config.js.bak'))->toBe($viteConfig)
        ->and($this->fresh->contents('resources/js/app.js.bak'))->toBe($entry);
});

it('finishes the setup of an app whose entry files were moved aside already', function () {
    rename($this->fresh->path('resources/js/app.js'), $this->fresh->path('resources/js/app.js.bak'));
    rename($this->fresh->path('vite.config.js'), $this->fresh->path('vite.config.js.bak'));

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('resources/js/app.ts'))->toBeTrue()
        ->and($this->fresh->has('resources/js/app.js'))->toBeFalse();
});

it('keeps the setup of an app that has its own, and prints what to set up', function (string $path, string $contents) {
    $this->fresh->put($path, $contents);
    $before = $this->fresh->contents($path);

    $this->artisan('keystone:install')
        ->expectsOutputToContain(app(Stubs::class)->path('app/Http/Middleware/HandleInertiaRequests.php'))
        ->assertSuccessful();

    expect($this->fresh->contents($path))->toBe($before)
        ->and($this->fresh->has('vite.config.js'))->toBeTrue()
        ->and($this->fresh->has('resources/js/app.js.bak'))->toBeFalse()
        ->and($this->fresh->has('resources/views/app.blade.php'))->toBeFalse()
        ->and($this->fresh->has('vite.config.ts'))->toBeFalse();
})->with([
    'an entry of its own' => ['resources/js/app.ts', "import './bootstrap'\n"],
    'a script of its own' => ['resources/js/app.js', "import './bootstrap';\n"],
    'a layout of its own loading the script' => ['resources/views/layouts/app.blade.php', "@vite(['resources/js/app.js'])\n"],
]);

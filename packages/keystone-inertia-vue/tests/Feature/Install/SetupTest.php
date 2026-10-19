<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

it('sets Inertia and Vue up', function (string $path) {
    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->contents($path))->toBe(file_get_contents(app(Stubs::class)->path($path)));
})->with([
    'middleware' => ['app/Http/Middleware/HandleInertiaRequests.php'],
    'entry' => ['resources/js/app.ts'],
    'root view' => ['resources/views/app.blade.php'],
    'TypeScript config' => ['tsconfig.json'],
    'Vite config' => ['vite.config.ts'],
]);

it("deletes the skeleton's Vite config and script, which the setup replaces", function () {
    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('vite.config.js'))->toBeFalse()
        ->and($this->fresh->has('resources/js/app.js'))->toBeFalse();
});

it('replaces a Vite config and script the app changed', function () {
    $this->fresh->put('vite.config.js', "// a config of its own\n");
    $this->fresh->put('resources/js/app.js', "import './echo';\n");

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('vite.config.js'))->toBeFalse()
        ->and($this->fresh->has('resources/js/app.js'))->toBeFalse()
        ->and($this->fresh->contents('vite.config.ts'))->toBe(file_get_contents(app(Stubs::class)->path('vite.config.ts')));
});

describe('bootstrap/app.php', function () {
    it('is replaced with one that appends HandleInertiaRequests to the web middleware', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('bootstrap/app.php'))
            ->toBe(file_get_contents(app(Stubs::class)->path('bootstrap/app.php')))
            ->toContain('HandleInertiaRequests::class');
    });

    it('is replaced when the app changed it', function () {
        $changed = str_replace('        //', "        \$middleware->trustProxies(at: '*');", $this->fresh->contents('bootstrap/app.php'));
        $this->fresh->put('bootstrap/app.php', $changed);

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('bootstrap/app.php'))->toBe(file_get_contents(app(Stubs::class)->path('bootstrap/app.php')));
    });

    it('is kept once it registers HandleInertiaRequests', function () {
        $this->artisan('keystone:install')->assertSuccessful();
        $changed = str_replace('->create();', '->withSchedule(fn () => null)->create();', $this->fresh->contents('bootstrap/app.php'));
        $this->fresh->put('bootstrap/app.php', $changed);

        $this->artisan('keystone:install')
            ->doesntExpectOutputToContain('bootstrap/app.php')
            ->assertSuccessful();

        expect($this->fresh->contents('bootstrap/app.php'))->toBe($changed);
    });
});

describe('the welcome page', function () {
    it('stops loading app.js, keeping its stylesheet', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('resources/views/welcome.blade.php'))
            ->toContain("@vite(['resources/css/app.css'])")
            ->not->toContain('app.js');
    });

    it('is left out of an app that has none, which is set up all the same', function () {
        unlink($this->fresh->path('resources/views/welcome.blade.php'));

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->has('resources/views/welcome.blade.php'))->toBeFalse()
            ->and($this->fresh->has('resources/js/app.ts'))->toBeTrue();
    });
});

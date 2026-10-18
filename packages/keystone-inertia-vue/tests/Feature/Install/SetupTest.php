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

it('prints what to remove when the Vite entry file is still there beside its .bak, and fails', function (string $path) {
    $this->fresh->put($path.'.bak', "// a copy the app made\n");
    $before = $this->fresh->contents($path);

    $this->artisan('keystone:install')
        ->expectsOutputToContain("Remove {$path} by hand")
        ->assertFailed();

    expect($this->fresh->contents($path))->toBe($before)
        ->and($this->fresh->contents($path.'.bak'))->toBe("// a copy the app made\n");
})->with([
    'the Vite config' => ['vite.config.js'],
    'the script' => ['resources/js/app.js'],
]);

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

describe('bootstrap/app.php', function () {
    it('appends HandleInertiaRequests to the web middleware of a bare app, with its import', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('bootstrap/app.php'))
            ->toContain("\nuse App\\Http\\Middleware\\HandleInertiaRequests;\nuse Illuminate\\Foundation\\Application;\n")
            ->toContain(implode("\n", [
                '    ->withMiddleware(function (Middleware $middleware): void {',
                '        $middleware->web(append: [',
                '            HandleInertiaRequests::class,',
                '        ]);',
                '    })',
            ]));
    });

    it('prints the registration when the middleware closure is not the skeleton\'s, and fails', function () {
        $before = str_replace('        //', "        \$middleware->trustProxies(at: '*');", $this->fresh->contents('bootstrap/app.php'));
        $this->fresh->put('bootstrap/app.php', $before);

        $this->artisan('keystone:install')
            ->expectsOutputToContain('$middleware->web(append: [HandleInertiaRequests::class]);')
            ->assertFailed();

        expect($this->fresh->contents('bootstrap/app.php'))->toBe($before);
    });
});

describe('the welcome page', function () {
    it('stops loading app.js on a bare app, keeping its stylesheet', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('resources/views/welcome.blade.php'))
            ->toContain("@vite(['resources/css/app.css'])")
            ->not->toContain('app.js');
    });

    it('prints the edit when the page loads app.js another way, and fails', function () {
        $before = "@vite('resources/js/app.js')\n";
        $this->fresh->put('resources/views/welcome.blade.php', $before);

        $this->artisan('keystone:install')
            ->expectsOutputToContain('resources/js/app.js')
            ->expectsOutputToContain('resources/views/welcome.blade.php')
            ->assertFailed();

        expect($this->fresh->contents('resources/views/welcome.blade.php'))->toBe($before);
    });

    it('is left out of an app that has none, which is set up all the same', function () {
        unlink($this->fresh->path('resources/views/welcome.blade.php'));

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->has('resources/views/welcome.blade.php'))->toBeFalse()
            ->and($this->fresh->has('resources/js/app.ts'))->toBeTrue();
    });
});

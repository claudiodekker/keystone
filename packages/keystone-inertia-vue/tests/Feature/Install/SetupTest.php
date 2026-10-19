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
    it('appends HandleInertiaRequests to the web middleware, with its import', function () {
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

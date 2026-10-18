<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\ServiceProvider as InertiaServiceProvider;

/**
 * Point the app at a fresh copy of the Laravel skeleton.
 */
trait InstallsIntoFreshApp
{
    /**
     * The skeleton copy the app points at.
     */
    protected FreshApp $fresh;

    /**
     * The loader of the copy's App classes, once mounted.
     */
    protected ?Closure $loader = null;

    /**
     * Copy the skeleton, point the app at it, and keep the installer's commands from running for real.
     */
    protected function setUpInstallsIntoFreshApp(): void
    {
        $this->fresh = FreshApp::copy();
        $this->app->setBasePath($this->fresh->path());

        config(['auth.providers.users.model' => 'App\\Models\\User']);
        Process::fake();
    }

    /**
     * Delete the copy.
     */
    protected function tearDownInstallsIntoFreshApp(): void
    {
        if ($this->loader !== null) {
            spl_autoload_unregister($this->loader);
        }

        $this->fresh->delete();
    }

    /**
     * Serve the copy's pages from the running app: its App classes, auth config, routes, views and Inertia pages.
     */
    protected function mountFreshApp(): void
    {
        $this->loader = function (string $class): void {
            if (! str_starts_with($class, 'App\\')) {
                return;
            }

            $file = $this->fresh->path('app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php');

            if (is_file($file)) {
                require $file;
            }
        };

        spl_autoload_register($this->loader, prepend: true);
        $this->app->register(InertiaServiceProvider::class);

        config([
            'auth' => require $this->fresh->path('config/auth.php'),
            'inertia.pages.paths' => [$this->fresh->path('resources/js/pages')],
        ]);

        Auth::forgetGuards();
        View::prependLocation($this->fresh->path('resources/views'));
        Route::middleware('web')->group($this->fresh->path('routes/web.php'));

        $this->withoutVite();
    }
}

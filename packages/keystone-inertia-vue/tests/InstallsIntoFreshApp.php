<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\ServiceProvider as InertiaServiceProvider;

trait InstallsIntoFreshApp
{
    /**
     * The skeleton copy the app points at.
     */
    protected FreshApp $fresh;

    /**
     * The loader of the copy's App classes, once mounted.
     */
    protected ?ClassLoader $loader = null;

    /**
     * Point the app at a fresh copy of the skeleton, with processes faked.
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
            $this->loader->unregister();
        }

        $this->fresh->delete();
    }

    /**
     * Serve the copy's pages from the running app.
     */
    protected function mountFreshApp(): void
    {
        $this->loader = new ClassLoader;
        $this->loader->addPsr4('App\\', $this->fresh->path('app'));
        $this->loader->register(true);
        $this->app->register(InertiaServiceProvider::class);

        config([
            'auth' => require $this->fresh->path('config/auth.php'),
            'inertia.pages.paths' => [$this->fresh->path('resources/js/pages')],
        ]);

        Auth::forgetGuards();
        View::prependLocation($this->fresh->path('resources/views'));
        Route::middleware('web')->group($this->fresh->path('routes/web.php'));
    }
}

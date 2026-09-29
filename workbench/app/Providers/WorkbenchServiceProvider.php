<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Point the keystone guard at the workbench's user model, outside the test suite.
     */
    public function register(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        config([
            'auth.guards.web.driver' => 'keystone',
            'auth.providers.users.model' => User::class,
        ]);
    }

    /**
     * Serve the Inertia-Vue stubs' pages and routes, outside the test suite, which wires its own.
     */
    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        View::addLocation(dirname(__DIR__, 2).'/resources/views');

        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/web.php');
    }
}

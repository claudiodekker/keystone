<?php

namespace Workbench\App\Providers;

use App\Http\Middleware\HandleInertiaRequests;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

/**
 * Set the workbench up as keystone:install sets up an app, reading the adapter's stubs where they are.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Point the guard at the keystone driver and the fixture user, as the installer's edits do, and the page checks at
     * the stubs' pages. Outside tests, keep the data in the skeleton's SQLite file rather than in memory.
     */
    public function register(): void
    {
        if (! $this->app->runningUnitTests()) {
            config(['database.default' => 'sqlite']);
        }

        config([
            'auth.guards.web.driver' => 'keystone',
            'auth.providers.users.model' => User::class,
            'inertia.pages.paths' => [dirname(__DIR__, 3).'/packages/keystone-inertia-vue/stubs/resources/js/pages'],
            'inertia.testing.ensure_pages_exist' => true,
        ]);
    }

    /**
     * Serve the workbench as an installed app is served.
     */
    public function boot(): void
    {
        $this->app->usePublicPath(dirname(__DIR__, 2).'/public');
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', HandleInertiaRequests::class);
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/packages/keystone/tests/Fixtures/Database/Migrations');
    }
}

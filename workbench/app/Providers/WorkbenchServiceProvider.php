<?php

namespace Workbench\App\Providers;

use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\ServiceProvider;

/**
 * Set the workbench up as keystone:install sets up an app, reading the adapter's stubs where they are.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Point the guard at the keystone driver and the fixture user, as the installer's edits do. Page-existence checks
     * stay off until the pages exist.
     */
    public function register(): void
    {
        config([
            'auth.guards.web.driver' => 'keystone',
            'auth.providers.users.model' => User::class,
            'inertia.testing.ensure_pages_exist' => false,
        ]);
    }

    /**
     * Load the fixture user table.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/packages/keystone/tests/Fixtures/Database/Migrations');
    }
}

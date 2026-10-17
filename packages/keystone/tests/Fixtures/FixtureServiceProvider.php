<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeChallengeType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeFormType;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakePinType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Set Testbench's app up the way keystone:install sets up a real one, with fake types in place of method packages.
 */
class FixtureServiceProvider extends ServiceProvider
{
    /**
     * Point the app's guard at the keystone driver and the fixture user, as the installer does.
     */
    public function register(): void
    {
        config([
            'auth.guards.web.driver' => 'keystone',
            'auth.providers.users.model' => User::class,
        ]);
    }

    /**
     * Register the fake types, the fixture routes, views and user table.
     */
    public function boot(): void
    {
        Keystone::credentialType(new FakeFormType);
        Keystone::credentialType(new FakePinType);
        Keystone::credentialType(new FakeChallengeType);

        Route::middleware('web')->group(__DIR__.'/routes/keystone.php');

        $this->loadViewsFrom(__DIR__.'/views', 'fixtures');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}

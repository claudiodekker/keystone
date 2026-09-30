<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Support\ServiceProvider;

class FixturesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.guards.web.driver' => 'keystone',
            'auth.providers.users.model' => User::class,
        ]);

        $this->app->bind('keystone.test-support.form', FormTypeSupport::class);
        $this->app->bind('keystone.test-support.code', fn () => new FormTypeSupport('code'));
    }

    public function boot(CredentialTypes $types): void
    {
        $types->register(new FormType);
        $types->register(new FormType(name: 'code', surfaces: ['challenge']));

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class PasswordServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/keystone-password.php', 'keystone-password');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(CredentialTypes $types): void
    {
        $types->register(new PasswordType);

        $this->publishes([__DIR__.'/../config/keystone-password.php' => config_path('keystone-password.php')], 'keystone-password-config');
    }
}

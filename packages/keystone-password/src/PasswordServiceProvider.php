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
        $this->app->singletonIf(PasswordType::class);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(CredentialTypes $types): void
    {
        $types->register($this->app->make(PasswordType::class));
    }
}

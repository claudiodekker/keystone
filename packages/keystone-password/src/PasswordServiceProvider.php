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
     * Bootstrap the package services.
     */
    public function boot(CredentialTypes $types): void
    {
        $types->register(new PasswordType);
    }
}

<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class TotpServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/keystone-totp.php', 'keystone-totp');
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(CredentialTypes $types): void
    {
        $types->register(new TotpType);

        $this->publishes([__DIR__.'/../config/keystone-totp.php' => config_path('keystone-totp.php')], 'keystone-totp-config');
    }
}

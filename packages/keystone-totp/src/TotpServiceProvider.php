<?php

namespace ClaudioDekker\Keystone\Totp;

use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
class TotpServiceProvider extends ServiceProvider
{
    /**
     * Merge the TOTP config under keystone.totp, with the app's values over it.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/totp.php', 'keystone.totp');
    }

    /**
     * Register the TOTP type.
     */
    public function boot(CredentialTypeRegistry $registry, Repository $config): void
    {
        $registry->register(new TotpType($config));
    }
}

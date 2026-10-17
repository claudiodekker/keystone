<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

/**
 * Register the password type with Keystone.
 *
 * @internal
 */
class PasswordServiceProvider extends ServiceProvider
{
    /**
     * Register the type as a plain instance, which the container never binds.
     */
    public function boot(): void
    {
        $config = $this->app->make(Repository::class);

        Keystone::credentialType(new PasswordType($config));
    }
}

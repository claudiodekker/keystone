<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;

/**
 * Register the password type with Keystone, and keep every password field untrimmed.
 *
 * @internal
 */
class PasswordServiceProvider extends ServiceProvider
{
    /**
     * The fields that hold a password, which no request may trim.
     *
     * @var list<string>
     */
    protected const array PASSWORD_FIELDS = ['current_password', 'password', 'password_confirmation'];

    /**
     * Register the password type and keep password fields untrimmed.
     *
     * TrimStrings merges this list with the app's own $except, so an app that empties $except still never trims them.
     */
    public function boot(): void
    {
        $config = $this->app->make(Repository::class);

        Keystone::credentialType(new PasswordType($config));
        TrimStrings::except(static::PASSWORD_FIELDS);
    }
}

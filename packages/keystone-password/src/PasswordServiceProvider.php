<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class PasswordServiceProvider extends ServiceProvider
{
    /**
     * The fields a password or a new password arrives in, which are never trimmed.
     */
    protected const array PASSWORD_FIELDS = ['password', 'current_password', 'password_confirmation'];

    /**
     * Bootstrap the package services.
     */
    public function boot(CredentialTypes $types): void
    {
        $types->register(new PasswordType);

        TrimStrings::except(self::PASSWORD_FIELDS);
    }
}

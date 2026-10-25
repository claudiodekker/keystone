<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * @api
 */
class Keystone
{
    /**
     * Get the guard Keystone's routes sign in with.
     *
     * @internal
     */
    public static function guard(): KeystoneGuard
    {
        $guard = Auth::guard();

        if (! $guard instanceof KeystoneGuard) {
            throw KeystoneMisconfiguredException::guardDriver();
        }

        return $guard;
    }

    /**
     * Get the app's user model, from the keystone guard's provider.
     *
     * @return class-string<Model&KeystoneUser>
     *
     * @internal
     */
    public static function userModel(): string
    {
        $provider = static::guard()->getProvider();

        if (! $provider instanceof EloquentUserProvider) {
            throw KeystoneMisconfiguredException::userProvider();
        }

        $model = $provider->getModel();

        if (! is_a($model, KeystoneUser::class, true)) {
            throw KeystoneMisconfiguredException::userModel($model);
        }

        return $model;
    }
}

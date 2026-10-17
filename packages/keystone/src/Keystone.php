<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Hold what the app and the method packages tell Keystone at boot.
 *
 * @api
 */
class Keystone
{
    /**
     * The registered credential types, by name.
     *
     * @var array<string, CredentialType>
     */
    protected static array $credentialTypes = [];

    /**
     * Register a credential type, as a plain instance from a method package's boot().
     *
     * @internal
     */
    public static function credentialType(CredentialType $type): void
    {
        static::$credentialTypes[$type->name()] = $type;
    }

    /**
     * Get the registered credential types.
     *
     * @internal
     */
    public static function credentialTypes(): CredentialTypes
    {
        return new CredentialTypes(static::$credentialTypes);
    }

    /**
     * Forget every registered credential type.
     *
     * @internal
     */
    public static function flushState(): void
    {
        static::$credentialTypes = [];
    }

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

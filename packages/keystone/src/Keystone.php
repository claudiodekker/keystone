<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

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
     * The classes that registered each name more than once, by name, in order.
     *
     * @var array<string, list<class-string<CredentialType>>>
     */
    protected static array $clashes = [];

    /**
     * Register a credential type, as a plain instance from a method package's boot().
     *
     * A name that is taken keeps the first type and records the clash, which boot reports.
     *
     * @internal
     */
    public static function credentialType(CredentialType $type): void
    {
        $name = $type->name();
        $taken = static::$credentialTypes[$name] ?? null;

        if ($taken === null) {
            static::$credentialTypes[$name] = $type;

            return;
        }

        static::$clashes[$name] ??= [$taken::class];
        static::$clashes[$name][] = $type::class;
    }

    /**
     * Get the registered credential types, limited to what the methods config allows.
     *
     * @internal
     */
    public static function credentialTypes(): CredentialTypes
    {
        $methods = MethodAllowList::from(Config::get('keystone.methods'));

        return new CredentialTypes(static::$credentialTypes, $methods, static::$clashes);
    }

    /**
     * Forget every registered credential type and every clash.
     *
     * @internal
     */
    public static function flushState(): void
    {
        static::$credentialTypes = [];
        static::$clashes = [];
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

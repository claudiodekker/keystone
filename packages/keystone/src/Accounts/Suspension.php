<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class Suspension
{
    /**
     * The users column that holds when the account was suspended, or null while it isn't.
     */
    public const string COLUMN = 'suspended_at';

    /**
     * Determine if the account with the id is suspended.
     */
    public static function isSuspended(int|string $id): bool
    {
        return static::account($id)->whereNotNull(static::COLUMN)->exists();
    }

    /**
     * Suspend the account with the id, so it can't sign in.
     */
    public static function suspend(int|string $id): void
    {
        static::account($id)->update([static::COLUMN => Date::now()]);
    }

    /**
     * Lift the suspension of the account with the id, so it can sign in again.
     */
    public static function lift(int|string $id): void
    {
        static::account($id)->update([static::COLUMN => null]);
    }

    /**
     * Get a query on the users row with the id, past every global scope and model event of the app's user model.
     */
    protected static function account(int|string $id): Builder
    {
        return Keystone::userModel()::query()
            ->withoutGlobalScopes()
            ->whereKey($id)
            ->toBase();
    }
}

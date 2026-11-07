<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Keystone;
use Illuminate\Database\Query\Builder;

/**
 * @internal
 */
class CredentialEpoch
{
    /**
     * The users column that holds the account's credential epoch.
     */
    public const string COLUMN = 'credential_epoch';

    /**
     * Get the epoch of the account with the id, or null when no account has it.
     */
    public static function of(int|string $id): ?int
    {
        $epoch = static::account($id)->value(static::COLUMN);

        return $epoch === null ? null : (int) $epoch;
    }

    /**
     * Move the epoch of the account with the id, ending every session stamped with the one before.
     */
    public static function move(int|string $id): void
    {
        static::account($id)->increment(static::COLUMN);
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

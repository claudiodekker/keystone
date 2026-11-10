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
     * Get the epoch of the account with the id, or null when no account has it or the account is suspended, so no session is on it.
     */
    public static function of(int|string $id): ?int
    {
        $epoch = static::account($id)
            ->whereNull(Suspension::COLUMN)
            ->value(static::COLUMN);

        return $epoch === null ? null : (int) $epoch;
    }

    /**
     * Lock the row of the account with the id until the transaction ends, so changes to one account run one after another, and get its epoch as of() reads it.
     */
    public static function lock(int|string $id): ?int
    {
        $row = static::account($id)
            ->lockForUpdate()
            ->first([static::COLUMN, Suspension::COLUMN]);

        if ($row === null || $row->{Suspension::COLUMN} !== null) {
            return null;
        }

        return (int) $row->{static::COLUMN};
    }

    /**
     * Move the epoch of the account with the id, ending every session stamped with the one before.
     */
    public static function move(int|string $id): void
    {
        static::account($id)->increment(static::COLUMN);
    }

    /**
     * Move every account's epoch in one query, ending every session.
     */
    public static function moveAll(): void
    {
        Keystone::userModel()::query()
            ->withoutGlobalScopes()
            ->toBase()
            ->increment(static::COLUMN);
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

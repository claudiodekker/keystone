<?php

namespace ClaudioDekker\Keystone\Exceptions;

use LogicException;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
class KeystoneMisconfiguredException extends LogicException
{
    /**
     * Create the exception for a default guard that doesn't use the keystone driver.
     */
    public static function guardDriver(): static
    {
        return new static('Keystone needs the keystone driver on the default auth guard.');
    }

    /**
     * Create the exception for a guard that names no user provider.
     */
    public static function missingUserProvider(string $guard): static
    {
        return new static("Keystone needs a user provider on the [{$guard}] guard.");
    }

    /**
     * Create the exception for a keystone guard whose provider isn't the eloquent one.
     */
    public static function userProvider(): static
    {
        return new static('Keystone needs the eloquent user provider on the keystone guard.');
    }

    /**
     * Create the exception for a user model that doesn't implement KeystoneUser.
     */
    public static function userModel(string $model): static
    {
        return new static("Keystone needs the user model [{$model}] to implement KeystoneUser.");
    }

    /**
     * Create the exception for a default connection on a database Keystone doesn't support.
     */
    public static function databaseDriver(string $driver): static
    {
        return new static("Keystone keeps its tables on MariaDB, MySQL, Postgres or SQLite, not [{$driver}].");
    }

    /**
     * Create the exception for an app that fails boot checks, listing every failure on a line of its own.
     *
     * @param  non-empty-list<string>  $failures
     */
    public static function bootFailures(array $failures): static
    {
        $lines = array_map(fn (string $failure) => "- {$failure}", $failures);

        return new static("Keystone refused to boot:\n".implode("\n", $lines));
    }
}

<?php

namespace ClaudioDekker\Keystone\Database;

use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use Illuminate\Support\Facades\DB;

/**
 * The collation that makes a string column compare as binary on the default connection.
 *
 * @internal
 */
class Collation
{
    /**
     * Get the default connection's binary collation, so rené@example.com and rene@example.com stay two addresses.
     */
    public static function binary(): string
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'mariadb', 'mysql' => 'utf8mb4_bin',
            'pgsql' => 'C',
            'sqlite' => 'BINARY',
            default => throw KeystoneMisconfiguredException::databaseDriver($driver),
        };
    }
}

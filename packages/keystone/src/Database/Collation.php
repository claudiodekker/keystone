<?php

namespace ClaudioDekker\Keystone\Database;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The collation that makes a string column compare as binary on the default connection [H4].
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
            default => throw new LogicException("Keystone keeps its tables on MariaDB, MySQL, Postgres or SQLite, not [{$driver}]."),
        };
    }
}

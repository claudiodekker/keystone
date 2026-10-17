<?php

use ClaudioDekker\Keystone\Database\Collation;

it('compares addresses as binary on each engine CI runs', function (string $connection, string $collation) {
    config(['database.default' => $connection]);

    expect(Collation::binary())->toBe($collation);
})->with([
    'MariaDB' => ['mariadb', 'utf8mb4_bin'],
    'MySQL' => ['mysql', 'utf8mb4_bin'],
    'Postgres' => ['pgsql', 'C'],
    'SQLite' => ['sqlite', 'BINARY'],
]);

it('names the engines it supports when the default connection is another', function () {
    config(['database.default' => 'sqlsrv']);

    expect(fn () => Collation::binary())->toThrow(LogicException::class, 'Keystone keeps its tables on MariaDB, MySQL, Postgres or SQLite, not [sqlsrv].');
});

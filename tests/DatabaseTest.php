<?php

use Illuminate\Support\Facades\DB;

it('starts each test from a freshly migrated database', function () {
    $migrations = $this->app['migration.repository'];

    expect($migrations->getRan())->not->toContain('left_by_an_earlier_test');

    $migrations->log('left_by_an_earlier_test', 1);
})->repeat(2);

it('runs on the engine the environment names', function () {
    expect(DB::getDriverName())->toBe(getenv('DB_CONNECTION'));
});

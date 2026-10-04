<?php

use Illuminate\Support\Facades\DB;

test('the suite runs on the database the CI matrix names', function () {
    expect(DB::connection()->getDriverName())->toBe(env('DB_CONNECTION', 'sqlite'));
});

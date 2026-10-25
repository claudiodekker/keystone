<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Models\OtherConnectionUser;

it('refuses a user model on a connection other than the default', function () {
    config(['auth.providers.users.model' => OtherConnectionUser::class]);
    $default = config('database.default');

    expect($this->bootFailures())->toBe([
        'The user model ['.OtherConnectionUser::class."] is on the [secondary] connection, but Keystone's tables are on the default [{$default}] connection.",
    ]);
});

it('boots with a user model that leaves the connection implicit', function () {
    expect($this->bootFailures())->toBe([]);
});

it('boots with a user model that names the default connection', function () {
    config(['auth.providers.users.model' => OtherConnectionUser::class, 'database.default' => 'secondary']);

    expect($this->bootFailures())->toBe([]);
});

it('refuses a user model on another connection whatever driver the default guard uses', function () {
    config([
        'auth.guards.web.driver' => 'session',
        'auth.providers.users.model' => OtherConnectionUser::class,
    ]);

    $default = config('database.default');

    expect($this->bootFailures())->toBe([
        'The user model ['.OtherConnectionUser::class."] is on the [secondary] connection, but Keystone's tables are on the default [{$default}] connection.",
    ]);
});

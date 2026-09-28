<?php

use ClaudioDekker\Keystone\Tests\Fixtures\User;

$columns = [
    'credential_epoch',
    'credential_epoch_moved_at',
    'has_second_factor',
    'has_recovery_codes',
    'deleted_at',
    'invalidated_at',
    'suspended_at',
];

it('keeps keystone columns out of the array form', function (string $column) {
    $user = User::factory()->create();
    $user->forceFill([$column => $column === 'credential_epoch' ? 3 : now()]);

    expect($user->toArray())->not->toHaveKey($column);
})->with($columns);

it('refuses to mass assign keystone columns', function (string $column) {
    $user = User::factory()->create();

    $user->fill([$column => 1]);

    expect($user->isDirty($column))->toBeFalse();
})->with($columns);

it('soft deletes the account', function () {
    $user = User::factory()->create();

    $user->delete();

    $this->assertSoftDeleted($user);
});

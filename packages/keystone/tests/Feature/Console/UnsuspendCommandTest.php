<?php

// Unpublished command: what keystone:unsuspend tells the operator, which no app changes.

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('tells the operator an account that is not suspended stays as it was, and fails', function () {
    $ada = User::factory()->create();

    $this->artisan('keystone:unsuspend', ['user' => $ada->getKey(), '--operator' => 'margaret'])
        ->expectsOutputToContain("The account [{$ada->getKey()}] isn't suspended.")
        ->assertFailed();
});

it('refuses an id no account has', function () {
    $this->artisan('keystone:unsuspend', ['user' => 4711, '--operator' => 'margaret'])
        ->expectsOutputToContain('No account has the id [4711].')
        ->assertFailed();

    $this->assertDatabaseCount(UserSecurityEvent::class, 0);
});

it('refuses to run without an operator, changing nothing', function (array $operator) {
    $ada = User::factory()->create();
    $this->artisan('keystone:suspend', ['user' => $ada->getKey(), '--operator' => 'margaret']);

    $this->artisan('keystone:unsuspend', ['user' => $ada->getKey(), ...$operator])
        ->expectsOutputToContain('Name the operator lifting the suspension with --operator.')
        ->assertFailed();

    $this->assertDatabaseCount(UserSecurityEvent::class, 1);
})->with([
    'without one' => [[]],
    'with an empty name' => [['--operator' => '']],
]);

it('tells the operator it lifted the suspension of the account', function () {
    $ada = User::factory()->create();
    $this->artisan('keystone:suspend', ['user' => $ada->getKey(), '--operator' => 'margaret']);

    $this->artisan('keystone:unsuspend', ['user' => $ada->getKey(), '--operator' => 'margaret'])
        ->expectsOutputToContain("Lifted the suspension of the account [{$ada->getKey()}].")
        ->assertSuccessful();
});

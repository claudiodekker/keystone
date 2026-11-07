<?php

// Unpublished command: what keystone:end-sessions tells the operator, which no app changes.

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('refuses to run without an account or --all, ending nothing', function () {
    $ada = User::factory()->create();

    $this->artisan('keystone:end-sessions', ['--operator' => 'margaret'])
        ->expectsOutputToContain('Name the account whose sessions end, or pass --all.')
        ->assertFailed();

    expect(CredentialEpoch::of($ada->getKey()))->toBe(0);
});

it('refuses an id no account has', function () {
    $this->artisan('keystone:end-sessions', ['user' => 4711, '--operator' => 'margaret'])
        ->expectsOutputToContain('No account has the id [4711].')
        ->assertFailed();

    $this->assertDatabaseCount(UserSecurityEvent::class, 0);
});

it('refuses to run without an operator, ending nothing', function (Closure $arguments) {
    $ada = User::factory()->create();

    $this->artisan('keystone:end-sessions', $arguments($ada))
        ->expectsOutputToContain('Name the operator ending the sessions with --operator.')
        ->assertFailed();

    expect(CredentialEpoch::of($ada->getKey()))->toBe(0);
    $this->assertDatabaseCount(UserSecurityEvent::class, 0);
})->with([
    'for the account' => [fn (User $ada) => ['user' => $ada->getKey()]],
    'for every account' => [fn () => ['--all' => true]],
    'with an empty name' => [fn (User $ada) => ['user' => $ada->getKey(), '--operator' => '']],
]);

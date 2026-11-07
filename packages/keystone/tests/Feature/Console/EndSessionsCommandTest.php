<?php

// Unpublished command: what keystone:end-sessions tells the operator, which no app changes.

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('refuses to run without an account or --all, ending nothing', function () {
    $ada = User::factory()->create();

    $this->artisan('keystone:end-sessions', ['--operator' => 'margaret'])
        ->expectsOutputToContain('Name the account whose sessions end, or pass --all.')
        ->assertFailed();

    expect(CredentialEpoch::of($ada->getKey()))->toBe(0);
});

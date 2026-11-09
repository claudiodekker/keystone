<?php

// Unpublished command: what keystone:unsuspend tells the operator, which no app changes.

use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('tells the operator an account that is not suspended stays as it was, and fails', function () {
    $ada = User::factory()->create();

    $this->artisan('keystone:unsuspend', ['user' => $ada->getKey(), '--operator' => 'margaret'])
        ->expectsOutputToContain("The account [{$ada->getKey()}] isn't suspended.")
        ->assertFailed();
});

<?php

// Unpublished command: what keystone:suspend tells the operator, which no app changes.

use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('tells the operator an account that is already suspended stays as it was, and fails', function () {
    $ada = User::factory()->create();
    $this->artisan('keystone:suspend', ['user' => $ada->getKey(), '--operator' => 'margaret']);

    $this->artisan('keystone:suspend', ['user' => $ada->getKey(), '--operator' => 'margaret'])
        ->expectsOutputToContain("The account [{$ada->getKey()}] is already suspended.")
        ->assertFailed();
});

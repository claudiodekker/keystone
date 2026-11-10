<?php

// Storage shape: what a spend leaves in the recovery codes table and what the model exposes, which no app can see through a page.

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('refuses a second spend of the same code, which deletes nothing', function () {
    $user = User::factory()->create();
    $code = UserRecoveryCode::factory()->for($user, 'user')->create();
    $kept = UserRecoveryCode::factory()->for($user, 'user')->create();
    $spend = fn () => app(AccountChanges::class)->change($user, fn (AccountChange $change) => $change->spendRecoveryCode($code));
    $spend();

    expect($spend)->toThrow(AccountChangeRefusedException::class);

    $this->assertModelMissing($code);
    $this->assertModelExists($kept);
});

it('leaves the digest out of the array form', function () {
    $code = UserRecoveryCode::factory()->create();

    expect($code->toArray())->not->toHaveKey('digest');
});

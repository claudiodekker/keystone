<?php

// Storage shape: what sits in the remember-me tokens table at rest, which no app can see through a page.

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Models\UserRememberToken;
use ClaudioDekker\Keystone\Session\RememberTokens;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it("keeps the SHA-256 digest of the browser's cookie with the account's credential epoch and the expiry, never the cookie itself", function () {
    config(['keystone.remember.lifetime_seconds' => 9 * 24 * 60 * 60]);
    $user = User::factory()->create();
    $user->newQuery()->whereKey($user->getKey())->update([CredentialEpoch::COLUMN => 7]);

    app(RememberTokens::class)->issue($user);

    $handed = app('cookie')->queued('__Host-keystone_remember')->getValue();
    $stored = UserRememberToken::query()->toBase()->where('user_id', $user->getKey())->sole();

    expect($stored->digest)->toBe(hash('sha256', $handed))
        ->and((int) $stored->epoch)->toBe(7)
        ->and(UserRememberToken::query()->sole()->expires_at->getTimestamp())->toBe(now()->addDays(9)->getTimestamp())
        ->and(json_encode($stored))->not->toContain($handed);
});

it('leaves the digest out of the array form', function () {
    $token = UserRememberToken::factory()->create();

    expect(array_keys($token->toArray()))->not->toContain('digest');
});

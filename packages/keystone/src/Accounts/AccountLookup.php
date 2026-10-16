<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserEmail;

/**
 * Resolve the one account a typed identifier names.
 *
 * @internal
 */
class AccountLookup
{
    /**
     * Resolve the account the identifier names.
     */
    public function resolve(string $identifier): KeystoneUser
    {
        $email = UserEmail::query()->where('address', $identifier)->firstOrFail();

        return $email->user()->sole();
    }
}

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
     * Resolve the account the identifier names, or null when it names none.
     */
    public function resolve(string $identifier): ?KeystoneUser
    {
        $email = UserEmail::query()->where('address', EmailAddress::normalize($identifier))->first();

        return $email?->user;
    }
}

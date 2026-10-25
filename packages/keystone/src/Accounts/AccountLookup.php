<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserEmail;

/**
 * @internal
 */
class AccountLookup
{
    /**
     * Resolve the account the identifier names, or null when it names none or more than one.
     */
    public function resolve(string $identifier): ?KeystoneUser
    {
        $address = EmailAddress::normalize($identifier);

        $verified = UserEmail::query()->whereVerified($address)->first();

        if ($verified !== null) {
            return $verified->user;
        }

        $holders = UserEmail::query()->whereCountingAsVerified($address)->limit(2)->get();

        if ($holders->count() !== 1) {
            return null;
        }

        return $holders->first()?->user;
    }
}

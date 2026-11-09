<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserEmail;

/**
 * @internal
 */
class AlertRecipients
{
    /**
     * Get the addresses the account's alerts go to.
     *
     * @return list<string>
     */
    public function of(KeystoneUser $account): array
    {
        $emails = UserEmail::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->whereNotNull('verified_address')
            ->orderBy('id')
            ->get();

        return array_values($emails->map(fn (UserEmail $email) => $email->address)->all());
    }
}

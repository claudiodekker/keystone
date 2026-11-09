<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Models\UserEmail;
use Illuminate\Database\Eloquent\Collection;

/**
 * @internal
 */
class AlertRecipients
{
    /**
     * Get the addresses the account's alerts go to: its verified ones, or the ones counting as verified when it holds none.
     *
     * @return list<string>
     */
    public function of(KeystoneUser $account): array
    {
        $emails = UserEmail::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->orderBy('id')
            ->get();

        $verified = $emails->whereNotNull('verified_address');

        if ($verified->isNotEmpty()) {
            return $this->addresses($verified);
        }

        return $this->addresses($this->countingAsVerified($emails));
    }

    /**
     * Get the unverified emails of an account that holds no verified address, but those another account holds verified.
     *
     * @param  Collection<int, UserEmail>  $emails
     * @return Collection<int, UserEmail>
     */
    protected function countingAsVerified(Collection $emails): Collection
    {
        $claimed = UserEmail::query()
            ->whereIn('verified_address', $emails->pluck('address'))
            ->pluck('verified_address');

        return $emails->whereNotIn('address', $claimed);
    }

    /**
     * Get the addresses the emails hold, in their order.
     *
     * @param  Collection<int, UserEmail>  $emails
     * @return list<string>
     */
    protected function addresses(Collection $emails): array
    {
        return array_values($emails->map(fn (UserEmail $email) => $email->address)->all());
    }
}

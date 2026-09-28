<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class SignInDecision
{
    /**
     * The columns, besides the soft-delete column, that bar an account from signing in when set.
     *
     * @var list<string>
     */
    protected const array BARRING_COLUMNS = ['invalidated_at', 'suspended_at'];

    /**
     * Decide what a proven account gets.
     */
    public function demand(Model&KeystoneUser $account): Demand
    {
        return $this->isBarred($account) ? Demand::REFUSE : Demand::SIGN_IN;
    }

    /**
     * Determine if the account, as read from its row, is deleted, invalidated or suspended.
     */
    public function isBarred(Model&KeystoneUser $account): bool
    {
        foreach ([...self::BARRING_COLUMNS, $account->getDeletedAtColumn()] as $column) {
            if (! is_null($account->getRawOriginal($column))) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use Closure;
use Illuminate\Database\Connection;

/**
 * @internal
 */
class AccountChanges
{
    /**
     * Create the account changes.
     */
    public function __construct(
        protected Connection $connection,
    ) {
        //
    }

    /**
     * Apply a change to the account while its row is locked, moving its credential epoch when the change ended anything.
     *
     * @param  Closure(AccountChange): void  $apply
     */
    public function change(KeystoneUser $account, Closure $apply): void
    {
        $this->connection->transaction(function () use ($account, $apply) {
            $this->lock($account);

            $change = new AccountChange;

            $apply($change);

            if ($change->movesEpoch()) {
                CredentialEpoch::move($account->getAuthIdentifier());
            }
        });
    }

    /**
     * Lock the account's row until the change commits, so changes to one account run one after another.
     */
    protected function lock(KeystoneUser $account): void
    {
        Keystone::userModel()::query()
            ->withoutGlobalScopes()
            ->whereKey($account->getAuthIdentifier())
            ->lockForUpdate()
            ->toBase()
            ->value(CredentialEpoch::COLUMN);
    }
}

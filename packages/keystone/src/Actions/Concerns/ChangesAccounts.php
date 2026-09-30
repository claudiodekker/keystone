<?php

namespace ClaudioDekker\Keystone\Actions\Concerns;

use ClaudioDekker\Keystone\AccountWrite;
use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
trait ChangesAccounts
{
    /**
     * Apply the write to the account under its row lock, reading its alert recipients first, and once it commits carry the mover's session over and record its events. A write started inside another write to the same account joins it.
     *
     * @template TResult
     *
     * @param  Closure(AccountWrite): TResult  $apply
     * @return TResult
     */
    protected function changeAccount(Model&KeystoneUser $account, Closure $apply): mixed
    {
        $open = AccountWrite::openOn($account);

        if (! is_null($open)) {
            return $apply($open);
        }

        $connection = $account->getConnection();

        [$write, $result] = $connection->transaction(function () use ($account, $apply) {
            $locked = $this->lockAccount($account);
            $addresses = new Addresses($locked);
            $write = new AccountWrite($locked, $addresses->recipientsOf($locked));

            return [$write, $write->hold($apply)];
        });

        $connection->afterCommit(function () use ($write) {
            $write->committed(Keystone::guard(), new SecurityEventRecorder);
        });

        return $result;
    }

    /**
     * Read the account afresh and hold its row until the write commits.
     *
     * @return Model&KeystoneUser
     */
    protected function lockAccount(Model&KeystoneUser $account): Model
    {
        /** @var Model&KeystoneUser */
        return $account->newQueryWithoutScopes()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
    }
}

<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * @mixin Command
 */
trait FindsAccounts
{
    /**
     * Find the account with the id, whatever its state, or say that no account has it.
     *
     * @return (Model&KeystoneUser)|null
     */
    protected function findAccountOrReport(string $id): ?Model
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = Keystone::guard()->userModel()->newQueryWithoutScopes()->whereKey($id)->first();

        if (is_null($account)) {
            $this->components->error("No account has the id [{$id}].");
        }

        return $account;
    }

    /**
     * Get the operator the command names, to record on the security event.
     */
    protected function operator(): ?string
    {
        $operator = $this->option('operator');

        return is_string($operator) ? $operator : null;
    }
}

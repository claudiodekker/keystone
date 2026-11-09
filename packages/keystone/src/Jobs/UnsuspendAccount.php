<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @api
 */
class UnsuspendAccount implements ShouldQueue
{
    use Queueable;

    /**
     * Whether the job is dropped when its account no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create the job that lifts the suspension of the account, for the operator who asked.
     */
    public function __construct(
        public KeystoneUser $account,
        public string $operator,
    ) {
        //
    }

    /**
     * Lift the account's suspension, so it can sign in again.
     */
    public function handle(AccountChanges $accountChanges): void
    {
        $accountChanges->change($this->account, fn (AccountChange $change) => $change->unsuspend($this->operator));
    }
}

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
class SuspendAccount implements ShouldQueue
{
    use Queueable;

    /**
     * Whether the job is dropped when its account no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create the job that suspends the account, for the operator who asked.
     */
    public function __construct(
        public KeystoneUser $account,
        public string $operator,
    ) {
        //
    }

    /**
     * Suspend the account, so it can't sign in until it is unsuspended.
     */
    public function handle(AccountChanges $accountChanges): void
    {
        $accountChanges->change($this->account, fn (AccountChange $change) => $change->suspend($this->operator));
    }
}

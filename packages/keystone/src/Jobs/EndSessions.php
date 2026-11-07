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
class EndSessions implements ShouldQueue
{
    use Queueable;

    /**
     * Whether the job is dropped when its account no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create the job that ends every session of the account, for the operator who asked.
     */
    public function __construct(
        public KeystoneUser $account,
        public string $operator,
    ) {
        //
    }

    /**
     * End every session of the account.
     */
    public function handle(AccountChanges $accountChanges): void
    {
        $accountChanges->change($this->account, fn (AccountChange $change) => $change->endSessions());
    }
}

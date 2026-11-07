<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\Accounts\AccountChanges;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @api
 */
class EndAllSessions implements ShouldQueue
{
    use Queueable;

    /**
     * Create the job that ends every account's sessions, for the operator who asked.
     */
    public function __construct(
        public string $operator,
    ) {
        //
    }

    /**
     * End every account's sessions.
     */
    public function handle(AccountChanges $accountChanges): void
    {
        $accountChanges->endAllSessions();
    }
}

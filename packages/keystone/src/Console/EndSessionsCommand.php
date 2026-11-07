<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Jobs\EndAllSessions;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use Illuminate\Console\Command;

/**
 * @internal
 */
class EndSessionsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:end-sessions
        {user? : The id of the account whose sessions end}
        {--all : End the sessions of every account}
        {--operator= : Who is ending the sessions, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End every session of an account on its next request';

    /**
     * End every session of the account, or of every account.
     */
    public function handle(AccountLookup $accounts): int
    {
        if ($this->option('all')) {
            EndAllSessions::dispatchSync($this->option('operator'));

            return self::SUCCESS;
        }

        $account = $accounts->find($this->argument('user'));

        EndSessions::dispatchSync($account, $this->option('operator'));

        return self::SUCCESS;
    }
}

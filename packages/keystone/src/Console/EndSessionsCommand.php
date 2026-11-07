<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
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
        {user : The id of the account whose sessions end}
        {--operator= : Who is ending the sessions, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End every session of an account on its next request';

    /**
     * End every session of the account.
     */
    public function handle(AccountLookup $accounts): int
    {
        $account = $accounts->find($this->argument('user'));

        EndSessions::dispatchSync($account, $this->option('operator'));

        return self::SUCCESS;
    }
}

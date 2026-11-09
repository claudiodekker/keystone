<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use Illuminate\Console\Command;

/**
 * @internal
 */
class SuspendCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:suspend
        {user : The id of the account to suspend}
        {--operator= : Who is suspending the account, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bar an account from signing in, keeping its email addresses';

    /**
     * Suspend the account the argument names.
     */
    public function handle(AccountLookup $accounts): int
    {
        $id = $this->argument('user');

        $account = $accounts->find($id);

        SuspendAccount::dispatchSync($account, $this->option('operator'));

        $this->components->info("Suspended the account [{$id}].");

        return self::SUCCESS;
    }
}

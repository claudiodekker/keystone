<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\AlreadySuspended;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'keystone:suspend')]
class SuspendCommand extends Command
{
    use FindsAccounts;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keystone:suspend
        {user : The id of the account to suspend}
        {--operator= : Who is suspending it, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bar an account from signing in and end its sessions, keeping its addresses';

    /**
     * Suspend the account, refusing one that already is.
     */
    public function handle(): int
    {
        $id = $this->argument('user');
        $account = $this->findAccountOrReport($id);

        if (is_null($account)) {
            return self::FAILURE;
        }

        try {
            SuspendAccount::dispatchSync($account, $this->operator());
        } catch (AlreadySuspended) {
            $this->components->error("Account [{$id}] is already suspended.");

            return self::FAILURE;
        }

        $this->components->info("Suspended account [{$id}].");

        return self::SUCCESS;
    }
}

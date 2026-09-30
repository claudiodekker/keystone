<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Jobs\UnsuspendAccount;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'keystone:unsuspend')]
class UnsuspendCommand extends Command
{
    use FindsAccounts;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keystone:unsuspend
        {user : The id of the account to unsuspend}
        {--operator= : Who is unsuspending it, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Let a suspended account sign in again';

    /**
     * Unsuspend the account, unless it isn't suspended.
     */
    public function handle(): int
    {
        $id = $this->argument('user');
        $account = $this->findAccountOrReport($id);

        if (is_null($account)) {
            return self::FAILURE;
        }

        $changed = (new UnsuspendAccount($account, $this->operator()))->handle();

        $this->components->info($changed ? "Unsuspended account [{$id}]." : "Account [{$id}] isn't suspended.");

        return self::SUCCESS;
    }
}

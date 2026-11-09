<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Jobs\UnsuspendAccount;
use Illuminate\Console\Command;

/**
 * @internal
 */
class UnsuspendCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:unsuspend
        {user : The id of the account whose suspension is lifted}
        {--operator= : Who is lifting the suspension, recorded on the security event}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Let a suspended account sign in again';

    /**
     * Lift the suspension of the account the argument names.
     */
    public function handle(AccountLookup $accounts): int
    {
        $id = $this->argument('user');

        $account = $accounts->find($id);

        try {
            UnsuspendAccount::dispatchSync($account, $this->option('operator'));
        } catch (AccountChangeRefusedException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->components->info("Lifted the suspension of the account [{$id}].");

        return self::SUCCESS;
    }

    /**
     * Tell the operator why nothing changed, and fail.
     */
    protected function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}

<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
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
        $operator = $this->option('operator');

        if (! is_string($operator) || $operator === '') {
            return $this->refuse('Name the operator suspending the account with --operator.');
        }

        $id = $this->argument('user');

        $account = $accounts->find($id);

        if ($account === null) {
            return $this->refuse("No account has the id [{$id}].");
        }

        try {
            SuspendAccount::dispatchSync($account, $operator);
        } catch (AccountChangeRefusedException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $this->components->info("Suspended the account [{$id}].");

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

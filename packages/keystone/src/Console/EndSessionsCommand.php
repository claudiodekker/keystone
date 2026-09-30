<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Jobs\EndEverySession;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'keystone:end-sessions')]
class EndSessionsCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keystone:end-sessions
        {user? : The id of the account whose sessions end}
        {--all : End every account\'s sessions}
        {--operator= : Who is ending them, recorded on the security event}
        {--force : End every account\'s sessions without asking, in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'End every session of one account, or of every account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = $this->argument('user');
        $all = $this->option('all') === true;
        $operator = $this->option('operator');
        $operator = is_string($operator) ? $operator : null;

        if ($all && is_null($id)) {
            return $this->endEverySession($operator);
        }

        if (! $all && ! is_null($id)) {
            return $this->endSessionsOf($id, $operator);
        }

        $this->components->error('Name one account by its id, or pass --all.');

        return self::FAILURE;
    }

    /**
     * End every session of the account with the id.
     */
    protected function endSessionsOf(string $id, ?string $operator): int
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = Keystone::guard()->userModel()->newQueryWithoutScopes()->whereKey($id)->first();

        if (is_null($account)) {
            $this->components->error("No account has the id [{$id}].");

            return self::FAILURE;
        }

        EndSessions::dispatchSync($account, $operator);

        $this->components->info("Ended every session of account [{$id}].");

        return self::SUCCESS;
    }

    /**
     * End every session of every account, once the operator confirms in production.
     */
    protected function endEverySession(?string $operator): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        EndEverySession::dispatchSync($operator);

        $this->components->info('Ended every session of every account.');

        return self::SUCCESS;
    }
}

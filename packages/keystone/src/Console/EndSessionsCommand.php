<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Jobs\EndEverySession;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'keystone:end-sessions')]
class EndSessionsCommand extends Command
{
    use ConfirmableTrait, FindsAccounts;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keystone:end-sessions
        {user? : The id of the account whose sessions end}
        {--all : End every account\'s sessions}
        {--operator= : Who is ending them, recorded on the security event}
        {--no-alert : End one account\'s sessions without alerting its owner}
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
        $operator = $this->operator();
        $alert = $this->option('no-alert') !== true;

        if ($all && is_null($id)) {
            return $this->endEverySession($operator);
        }

        if (! $all && ! is_null($id)) {
            return $this->endSessionsOf($id, $operator, $alert);
        }

        $this->components->error('Name one account by its id, or pass --all.');

        return self::FAILURE;
    }

    /**
     * End every session of the account with the id, alerting its owner unless suppressed.
     */
    protected function endSessionsOf(string $id, ?string $operator, bool $alert): int
    {
        $account = $this->findAccountOrReport($id);

        if (is_null($account)) {
            return self::FAILURE;
        }

        EndSessions::dispatchSync($account, $operator, $alert);

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

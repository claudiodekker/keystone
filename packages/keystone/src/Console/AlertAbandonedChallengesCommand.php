<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\SignIn\PendingChallenges;
use Illuminate\Console\Command;

/**
 * @internal
 */
class AlertAbandonedChallengesCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:alert-abandoned-challenges';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Alert the owners of accounts whose challenge a browser they don\'t know left unanswered';

    /**
     * Record every abandoned challenge and alert its account's owner.
     */
    public function handle(PendingChallenges $challenges): int
    {
        $abandoned = $challenges->alertAbandoned();

        $this->components->info("Recorded {$abandoned} abandoned challenges.");

        return self::SUCCESS;
    }
}

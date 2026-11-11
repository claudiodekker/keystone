<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Devices\KnownDevices;
use ClaudioDekker\Keystone\Session\RememberTokens;
use Illuminate\Console\Command;

/**
 * @internal
 */
class PruneCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete what Keystone keeps past its retention or lifetime, such as browsers no account signed in from for that long';

    /**
     * Delete everything past its retention or lifetime.
     */
    public function handle(KnownDevices $devices, RememberTokens $rememberTokens): int
    {
        $forgotten = $devices->prune();
        $expired = $rememberTokens->prune();

        $this->components->info("Forgot {$forgotten} known devices.");
        $this->components->info("Deleted {$expired} expired remember-me tokens.");

        return self::SUCCESS;
    }
}

<?php

namespace ClaudioDekker\Keystone\Console;

use ClaudioDekker\Keystone\Devices\KnownDevices;
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
    protected $description = 'Delete what Keystone keeps past its retention, such as browsers no account signed in from for that long';

    /**
     * Delete everything past its retention.
     */
    public function handle(KnownDevices $devices): int
    {
        $forgotten = $devices->prune();

        $this->components->info("Forgot {$forgotten} known devices.");

        return self::SUCCESS;
    }
}

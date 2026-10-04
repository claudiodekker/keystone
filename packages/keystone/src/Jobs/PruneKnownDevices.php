<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KnownDevices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @internal
 */
class PruneKnownDevices implements ShouldQueue
{
    use Queueable;

    /**
     * Forget every device nobody signed in from within the retention.
     */
    public function handle(): void
    {
        (new KnownDevices(Keystone::guard()->userModel()))->prune();
    }
}

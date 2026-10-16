<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Count its runs in the cache, so a test can tell the job ran.
 */
class RecordsItsRun implements ShouldQueue
{
    use Queueable;

    /**
     * Record the run.
     */
    public function handle(): void
    {
        Cache::increment('runs');
    }
}

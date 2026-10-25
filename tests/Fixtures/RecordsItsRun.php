<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

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

<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @api
 */
class EndEverySession implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?string $operator = null,
    ) {
        //
    }

    /**
     * End every session of every account, recording that an operator did.
     */
    public function handle(): void
    {
        (new AccountChanges(Keystone::guard()))->endEverySession(operator: $this->operator);
    }
}

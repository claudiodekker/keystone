<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

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
     * End every session of every account, the mover's own included, in one update, and log that an operator did once.
     */
    public function handle(): void
    {
        $users = Keystone::guard()->userModel();
        $movedAt = $users->fromDateTime(Date::now());

        $users->newQueryWithoutScopes()->toBase()->increment('credential_epoch', extra: ['credential_epoch_moved_at' => $movedAt]);

        $users->getConnection()->afterCommit(fn () => (new SecurityEventRecorder)->record(
            SecurityEventType::SESSIONS_TERMINATED,
            actor: Actor::OPERATOR,
            reason: 'keystone.every_account',
            operator: $this->operator,
        ));
    }
}

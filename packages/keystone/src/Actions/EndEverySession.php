<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Support\Facades\Date;

/**
 * @api
 */
class EndEverySession
{
    /**
     * End every session of every account, the mover's own included, in one update, and log once that an operator did.
     */
    public function handle(?string $operator = null): void
    {
        $users = Keystone::guard()->userModel();
        $movedAt = $users->fromDateTime(Date::now());

        $users->newQueryWithoutScopes()->toBase()->increment('credential_epoch', extra: ['credential_epoch_moved_at' => $movedAt]);

        $users->getConnection()->afterCommit(function () use ($operator) {
            $recorder = new SecurityEventRecorder;

            $recorder->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, reason: 'keystone.every_account', operator: $operator);
        });
    }
}

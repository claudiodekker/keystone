<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * @api
 */
class EndSessions implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Drop the job when the account is gone before it runs.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Model&KeystoneUser $account,
        public ?string $operator = null,
        public bool $alert = true,
    ) {
        //
    }

    /**
     * End every session of the account, recording that an operator did and alerting its owner unless the operator suppressed it.
     */
    public function handle(): void
    {
        (new AccountChanges(Keystone::guard()))->change($this->account, function (AccountChange $change) {
            $change->endSessions();
            $change->forgetDevices();

            $change->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, operator: $this->operator, alert: $this->alert);
        });
    }
}

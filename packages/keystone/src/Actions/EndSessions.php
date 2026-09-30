<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Database\Eloquent\Model;

/**
 * @api
 */
class EndSessions
{
    use ChangesAccounts;

    /**
     * End every session of the account but the mover's own, recording that an operator did and alerting its owner unless the operator suppressed it.
     */
    public function handle(Model&KeystoneUser $account, ?string $operator = null, bool $alert = true): void
    {
        $this->changeAccount($account, function (Model&KeystoneUser $account) use ($operator, $alert) {
            $this->endSessions($account);
            $this->record(SecurityEventType::SESSIONS_TERMINATED, $account, actor: Actor::OPERATOR, operator: $operator, alert: $alert);
        });
    }
}

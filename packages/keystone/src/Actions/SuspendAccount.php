<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\AccountWrite;
use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Exceptions\AlreadySuspended;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @api
 */
class SuspendAccount
{
    use ChangesAccounts;

    /**
     * Bar the account from signing in and end its sessions, recording that an operator did and alerting its owner.
     *
     * @throws AlreadySuspended
     */
    public function handle(Model&KeystoneUser $account, ?string $operator = null): void
    {
        $this->changeAccount($account, function (AccountWrite $write) use ($operator) {
            if (! is_null($write->account->getRawOriginal('suspended_at'))) {
                throw new AlreadySuspended;
            }

            $write->update(['suspended_at' => $write->account->fromDateTime(Date::now())]);
            $write->endSessions();
            $write->record(SecurityEventType::ACCOUNT_SUSPENDED, actor: Actor::OPERATOR, operator: $operator);
        });
    }
}

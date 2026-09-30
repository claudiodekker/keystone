<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\AccountWrite;
use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Exceptions\NotSuspended;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventType;
use Illuminate\Database\Eloquent\Model;

/**
 * @api
 */
class UnsuspendAccount
{
    use ChangesAccounts;

    /**
     * Let the suspended account sign in again, recording that an operator did and alerting its owner.
     *
     * @throws NotSuspended
     */
    public function handle(Model&KeystoneUser $account, ?string $operator = null): void
    {
        $this->changeAccount($account, function (AccountWrite $write) use ($operator) {
            if (is_null($write->account->getRawOriginal('suspended_at'))) {
                throw new NotSuspended;
            }

            $write->update(['suspended_at' => null]);
            $write->record(SecurityEventType::ACCOUNT_UNSUSPENDED, actor: Actor::OPERATOR, operator: $operator);
        });
    }
}

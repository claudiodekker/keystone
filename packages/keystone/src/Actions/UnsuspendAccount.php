<?php

namespace ClaudioDekker\Keystone\Actions;

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
        $this->changeAccount($account, function (Model&KeystoneUser $account) use ($operator) {
            if (is_null($account->getRawOriginal('suspended_at'))) {
                throw new NotSuspended;
            }

            $this->writeColumns($account, ['suspended_at' => null]);
            $this->record(SecurityEventType::ACCOUNT_UNSUSPENDED, $account, actor: Actor::OPERATOR, operator: $operator);
        });
    }
}

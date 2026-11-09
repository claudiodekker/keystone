<?php

namespace ClaudioDekker\Keystone\Jobs;

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * @api
 */
class EndSessions implements ShouldQueue
{
    use Queueable;

    /**
     * Whether the job is dropped when its account no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Whether the account's owner is mailed that its sessions ended.
     */
    public bool $alerts = true;

    /**
     * Create the job that ends every session of the account, for the operator who asked.
     */
    public function __construct(
        public KeystoneUser $account,
        public string $operator,
    ) {
        //
    }

    /**
     * End the sessions without mailing the account's owner.
     *
     * @return $this
     */
    public function withoutAlert(): static
    {
        $this->alerts = false;

        return $this;
    }

    /**
     * End every session of the account.
     */
    public function handle(AccountChanges $accountChanges): void
    {
        $accountChanges->change($this->account, $this->endSessions(...));
    }

    /**
     * End the account's sessions within its change, alerting its owner unless the operator asked not to.
     */
    protected function endSessions(AccountChange $change): void
    {
        if (! $this->alerts) {
            $change->endSessionsWithoutAlert($this->operator);

            return;
        }

        $change->endSessions($this->operator);
    }
}

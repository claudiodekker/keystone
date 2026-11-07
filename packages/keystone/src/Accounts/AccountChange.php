<?php

namespace ClaudioDekker\Keystone\Accounts;

/**
 * @internal
 */
class AccountChange
{
    /**
     * Whether the change removed, replaced or ended anything, which moves the account's credential epoch.
     */
    protected bool $movesEpoch = false;

    /**
     * End every session of the account.
     */
    public function endSessions(): void
    {
        $this->movesEpoch = true;
    }

    /**
     * Determine if the change removed, replaced or ended anything, so the account's credential epoch moves.
     */
    public function movesEpoch(): bool
    {
        return $this->movesEpoch;
    }
}

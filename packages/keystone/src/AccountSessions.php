<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class AccountSessions
{
    /**
     * Create a new account sessions instance.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table,
        protected Session $session,
        protected Model&KeystoneUser $account,
    ) {
        //
    }

    /**
     * Determine if the account may be signed in on a session other than this one, which no epoch move ended since its last request.
     */
    public function mayHaveOthers(): bool
    {
        $movedAt = $this->account->getRawOriginal('credential_epoch_moved_at');

        return $this->others()
            ->when($movedAt !== null, fn (Builder $query) => $query->where('last_activity', '>=', Date::parse($movedAt)->getTimestamp()))
            ->exists();
    }

    /**
     * Delete the row of every session of the account but this one.
     */
    public function deleteOthers(): void
    {
        $this->others()->delete();
    }

    /**
     * Get a query for the rows of the account's sessions other than this one.
     */
    protected function others(): Builder
    {
        return $this->connection->table($this->table)
            ->where('user_id', $this->account->getKey())
            ->where('id', '!=', $this->session->getId());
    }
}

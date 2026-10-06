<?php

namespace ClaudioDekker\Keystone;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class AccountChanges
{
    /**
     * Create a new account changes instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Apply the change under the account's row lock, moving its credential epoch when the change calls for it, and record its events once committed.
     *
     * @template TResult
     *
     * @param  Closure(AccountChange): TResult  $apply
     * @return TResult
     */
    public function change(Model&KeystoneUser $account, Closure $apply): mixed
    {
        $connection = $account->getConnection();

        [$change, $result, $movedFrom] = $connection->transaction(function () use ($account, $apply) {
            $locked = $this->lock($account);
            $addresses = new Addresses($locked);
            $recipients = $addresses->recipientsOf($locked);
            $change = new AccountChange($locked, $recipients, new Credentials($locked), new RecoveryCodes($locked), new KnownDevices($locked));
            $result = $apply($change);
            $movedFrom = $change->movesEpoch() ? $this->moveEpoch($locked) : null;

            return [$change, $result, $movedFrom];
        });

        $connection->afterCommit(function () use ($change, $movedFrom) {
            $this->committed($change, $movedFrom);
        });

        return $result;
    }

    /**
     * End every session of every account, the mover's own included, forget every known device, and log it once.
     */
    public function endEverySession(?string $operator = null): void
    {
        $users = $this->guard->userModel();
        $movedAt = $users->fromDateTime(Date::now());

        $users->getConnection()->transaction(function () use ($users, $movedAt) {
            $users->newQueryWithoutScopes()->toBase()->increment('credential_epoch', extra: ['credential_epoch_moved_at' => $movedAt]);

            (new KnownDevices($users))->forgetAll();
        });

        $users->getConnection()->afterCommit(function () use ($operator) {
            $this->recorder->record(SecurityEventType::SESSIONS_TERMINATED, actor: Actor::OPERATOR, reason: 'keystone.every_account', operator: $operator);
        });
    }

    /**
     * Read the account afresh and hold its row until the change commits.
     *
     * @return Model&KeystoneUser
     */
    protected function lock(Model&KeystoneUser $account): Model
    {
        /** @var Model&KeystoneUser */
        return $account->newQueryWithoutScopes()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Move the locked account's credential epoch on by one, returning the epoch it moved from.
     */
    protected function moveEpoch(Model&KeystoneUser $account): int
    {
        $from = (int) $account->getRawOriginal('credential_epoch');

        $account->forceFill([
            'credential_epoch' => $from + 1,
            'credential_epoch_moved_at' => Date::now(),
        ])->saveQuietly();

        return $from;
    }

    /**
     * Carry the mover's own session over the epoch move and record the change's events.
     */
    protected function committed(AccountChange $change, ?int $movedFrom): void
    {
        if ($movedFrom !== null) {
            $this->guard->carryOver($change->account, movedFrom: $movedFrom);
        }

        foreach ($change->events() as $record) {
            $record($this->recorder);
        }
    }
}

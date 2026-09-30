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
        $connection = $this->guard->userModel()->getConnection();

        [$change, $result, $movedFrom] = $connection->transaction(function () use ($account, $apply) {
            $locked = $this->lock($account);
            $recipients = (new Addresses($this->guard->userModel()))->recipientsOf($locked);
            $change = new AccountChange($locked, $recipients, new Credentials($this->guard->userModel()));
            $result = $apply($change);
            $movedFrom = $change->movesEpoch() ? $this->moveEpoch($locked) : null;

            return [$change, $result, $movedFrom];
        });

        $connection->afterCommit(fn () => $this->committed($change, $movedFrom));

        return $result;
    }

    /**
     * End every session of every account, the mover's own included, and log it once.
     */
    public function endEverySession(?string $operator = null): void
    {
        $users = $this->guard->userModel();
        $movedAt = $users->fromDateTime(Date::now());

        $users->newQueryWithoutScopes()->toBase()->increment('credential_epoch', extra: ['credential_epoch_moved_at' => $movedAt]);

        $users->getConnection()->afterCommit(fn () => $this->recorder->record(
            SecurityEventType::SESSIONS_TERMINATED,
            actor: Actor::OPERATOR,
            reason: 'keystone.every_account',
            operator: $operator,
        ));
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
        $to = $from + 1;
        $moved = [
            'credential_epoch' => $to,
            'credential_epoch_moved_at' => $account->fromDateTime(Date::now()),
        ];

        $account->newQueryWithoutScopes()->toBase()->where($account->getKeyName(), $account->getKey())->update($moved);

        $account->setRawAttributes([...$account->getAttributes(), ...$moved], sync: true);

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

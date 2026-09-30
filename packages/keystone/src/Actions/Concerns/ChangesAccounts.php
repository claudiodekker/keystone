<?php

namespace ClaudioDekker\Keystone\Actions\Concerns;

use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
trait ChangesAccounts
{
    /**
     * The addresses that hear about the write, read before it applies.
     *
     * @var list<string>
     */
    protected array $recipients = [];

    /**
     * The credential epoch the write moved the account from, when it ended the account's sessions.
     */
    protected ?int $movedFrom = null;

    /**
     * The events to record once the write commits.
     *
     * @var list<Closure(SecurityEventRecorder): void>
     */
    protected array $pendingEvents = [];

    /**
     * Apply the write to the account under its row lock, reading its alert recipients first, and once it commits carry the mover's session over and record its events.
     *
     * @template TResult
     *
     * @param  Closure(Model&KeystoneUser): TResult  $write
     * @return TResult
     */
    protected function changeAccount(Model&KeystoneUser $account, Closure $write): mixed
    {
        $connection = $account->getConnection();

        [$locked, $result] = $connection->transaction(function () use ($account, $write) {
            $locked = $this->lockAccount($account);
            $addresses = new Addresses($locked);

            $this->recipients = $addresses->recipientsOf($locked);
            $this->movedFrom = null;
            $this->pendingEvents = [];

            return [$locked, $write($locked)];
        });

        $movedFrom = $this->movedFrom;
        $events = $this->pendingEvents;

        $connection->afterCommit(function () use ($locked, $movedFrom, $events) {
            if ($movedFrom !== null) {
                Keystone::guard()->carryOver($locked, movedFrom: $movedFrom);
            }

            $recorder = new SecurityEventRecorder;

            foreach ($events as $record) {
                $record($recorder);
            }
        });

        return $result;
    }

    /**
     * End every session of the account but the mover's own, moving its credential epoch once however often the write ends them.
     */
    protected function endSessions(Model&KeystoneUser $account): void
    {
        if ($this->movedFrom !== null) {
            return;
        }

        $this->movedFrom = (int) $account->getRawOriginal('credential_epoch');

        $this->writeColumns($account, [
            'credential_epoch' => $this->movedFrom + 1,
            'credential_epoch_moved_at' => $account->fromDateTime(Date::now()),
        ]);
    }

    /**
     * Record the event about the account once the write commits, alerting the recipients read before it unless suppressed.
     */
    protected function record(
        SecurityEventType $type,
        Model&KeystoneUser $account,
        Actor $actor = Actor::USER,
        ?string $operator = null,
        bool $alert = true,
    ): void {
        $recipients = $this->recipients;

        $this->pendingEvents[] = function (SecurityEventRecorder $recorder) use ($type, $account, $actor, $operator, $recipients, $alert) {
            $recorder->record($type, account: $account, actor: $actor, operator: $operator, recipients: $recipients, alert: $alert);
        };
    }

    /**
     * Write the columns to the account's row and to the locked model, bypassing its scopes and events.
     *
     * @param  array<string, mixed>  $columns
     */
    protected function writeColumns(Model&KeystoneUser $account, array $columns): void
    {
        $account->newQueryWithoutScopes()->toBase()->where($account->getKeyName(), $account->getKey())->update($columns);

        $account->setRawAttributes([
            ...$account->getAttributes(),
            ...$columns,
        ], sync: true);
    }

    /**
     * Read the account afresh and hold its row until the write commits.
     *
     * @return Model&KeystoneUser
     */
    protected function lockAccount(Model&KeystoneUser $account): Model
    {
        /** @var Model&KeystoneUser */
        return $account->newQueryWithoutScopes()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
    }
}

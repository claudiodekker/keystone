<?php

namespace ClaudioDekker\Keystone;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class AccountWrite
{
    /**
     * The writes open right now, keyed by the account they write to.
     *
     * @var array<string, self>
     */
    protected static array $open = [];

    /**
     * The credential epoch the write moved the account from, when it ended the account's sessions.
     */
    protected ?int $movedFrom = null;

    /**
     * The events to record once the write commits.
     *
     * @var list<Closure(SecurityEventRecorder): void>
     */
    protected array $events = [];

    /**
     * Create a new account write instance.
     *
     * @param  list<string>  $recipients
     */
    public function __construct(
        public readonly Model&KeystoneUser $account,
        public readonly array $recipients,
    ) {
        //
    }

    /**
     * Get the write open on the account, which a write started inside it joins.
     */
    public static function openOn(Model&KeystoneUser $account): ?self
    {
        return static::$open[static::keyOf($account)] ?? null;
    }

    /**
     * Apply the changes with this write open on its account, so a write started inside them joins it.
     *
     * @template TResult
     *
     * @param  Closure(self): TResult  $apply
     * @return TResult
     */
    public function hold(Closure $apply): mixed
    {
        $key = static::keyOf($this->account);
        static::$open[$key] = $this;

        try {
            return $apply($this);
        } finally {
            unset(static::$open[$key]);
        }
    }

    /**
     * Write the columns to the account's row and to the locked model, bypassing its scopes and events.
     *
     * @param  array<string, mixed>  $columns
     */
    public function update(array $columns): void
    {
        $this->account->newQueryWithoutScopes()->toBase()->where($this->account->getKeyName(), $this->account->getKey())->update($columns);

        $this->account->setRawAttributes([
            ...$this->account->getAttributes(),
            ...$columns,
        ], sync: true);
    }

    /**
     * End every session of the account but the mover's own, moving its credential epoch once however often the write ends them.
     */
    public function endSessions(): void
    {
        if ($this->movedFrom !== null) {
            return;
        }

        $this->movedFrom = (int) $this->account->getRawOriginal('credential_epoch');

        $this->update([
            'credential_epoch' => $this->movedFrom + 1,
            'credential_epoch_moved_at' => $this->account->fromDateTime(Date::now()),
        ]);
    }

    /**
     * Record the event about the account once the write commits, alerting the recipients read before it unless suppressed.
     */
    public function record(SecurityEventType $type, Actor $actor = Actor::USER, ?string $operator = null, bool $alert = true): void
    {
        $this->events[] = function (SecurityEventRecorder $recorder) use ($type, $actor, $operator, $alert) {
            $recorder->record($type, account: $this->account, actor: $actor, operator: $operator, recipients: $this->recipients, alert: $alert);
        };
    }

    /**
     * Carry the mover's own session over the epoch move and record the write's events.
     */
    public function committed(KeystoneGuard $guard, SecurityEventRecorder $recorder): void
    {
        if ($this->movedFrom !== null) {
            $guard->carryOver($this->account, movedFrom: $this->movedFrom);
        }

        foreach ($this->events as $record) {
            $record($recorder);
        }
    }

    /**
     * Get the key that names the account's row across connections and tables.
     */
    protected static function keyOf(Model&KeystoneUser $account): string
    {
        return implode('|', [$account->getConnection()->getName(), $account->getTable(), $account->getKey()]);
    }
}

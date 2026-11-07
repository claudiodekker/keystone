<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Closure;

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
     * The security events the change records once it commits.
     *
     * @var list<Closure(Recorder): void>
     */
    protected array $events = [];

    /**
     * Create the change to the account.
     */
    public function __construct(
        protected KeystoneUser $account,
    ) {
        //
    }

    /**
     * End every session of the account, for the operator who asked.
     */
    public function endSessions(string $operator): void
    {
        $this->movesEpoch = true;

        $this->events[] = fn (Recorder $recorder) => $recorder->record(
            SecurityEventType::SESSIONS_TERMINATED,
            account: $this->account,
            operator: $operator,
        );
    }

    /**
     * Determine if the change removed, replaced or ended anything, so the account's credential epoch moves.
     */
    public function movesEpoch(): bool
    {
        return $this->movesEpoch;
    }

    /**
     * Record the change's security events.
     */
    public function record(Recorder $recorder): void
    {
        foreach ($this->events as $event) {
            $event($recorder);
        }
    }
}

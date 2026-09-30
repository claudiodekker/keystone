<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\AlreadySuspended;
use ClaudioDekker\Keystone\Exceptions\NotSuspended;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class AccountChange
{
    /**
     * Whether the change removed, replaced or ended something, so the account's other sessions must end.
     */
    protected bool $movesEpoch = false;

    /**
     * The events to record once the change commits.
     *
     * @var list<Closure(SecurityEventRecorder): void>
     */
    protected array $events = [];

    /**
     * Create a new account change instance.
     *
     * @param  list<string>  $recipients
     */
    public function __construct(
        public readonly Model&KeystoneUser $account,
        public readonly array $recipients,
        protected Credentials $credentials,
    ) {
        //
    }

    /**
     * Store a credential of the type on the account.
     */
    public function addCredential(
        CredentialType $type,
        ?string $identifier,
        #[\SensitiveParameter] ?string $secret,
        ?string $label = null,
    ): int {
        return $this->credentials->store(
            $this->account,
            $type,
            identifier: $identifier,
            secret: $secret,
            label: $label,
        );
    }

    /**
     * Replace the credential's secret with a rehash of the same secret, only while it still holds the one that was verified.
     */
    public function rehash(StoredCredential $credential, string $type, #[\SensitiveParameter] string $secret): bool
    {
        return $this->credentials->replaceSecret($credential, type: $type, secret: $secret);
    }

    /**
     * Move the credential's secret on, only while it still holds the one that was verified.
     */
    public function advance(StoredCredential $credential, string $type, #[\SensitiveParameter] string $secret): bool
    {
        return $this->credentials->replaceSecret($credential, type: $type, secret: $secret);
    }

    /**
     * End every session of the account but the mover's own.
     */
    public function endSessions(): void
    {
        $this->movesEpoch = true;
    }

    /**
     * Bar the account from signing in and end its sessions.
     *
     * @throws AlreadySuspended
     */
    public function suspend(): void
    {
        if (! is_null($this->account->getRawOriginal('suspended_at'))) {
            throw new AlreadySuspended;
        }

        $this->account->forceFill(['suspended_at' => Date::now()])->saveQuietly();

        $this->endSessions();
    }

    /**
     * Lift the account's suspension.
     *
     * @throws NotSuspended
     */
    public function unsuspend(): void
    {
        if (is_null($this->account->getRawOriginal('suspended_at'))) {
            throw new NotSuspended;
        }

        $this->account->forceFill(['suspended_at' => null])->saveQuietly();
    }

    /**
     * Record the event about the account once the change commits, alerting the recipients read before it unless suppressed.
     */
    public function record(SecurityEventType $type, Actor $actor = Actor::USER, ?string $operator = null, bool $alert = true): void
    {
        $this->events[] = fn (SecurityEventRecorder $recorder) => $recorder->record(
            $type,
            account: $this->account,
            actor: $actor,
            operator: $operator,
            recipients: $this->recipients,
            alert: $alert,
        );
    }

    /**
     * Determine if the change must move the account's credential epoch.
     */
    public function movesEpoch(): bool
    {
        return $this->movesEpoch;
    }

    /**
     * Get the events to record once the change commits.
     *
     * @return list<Closure(SecurityEventRecorder): void>
     */
    public function events(): array
    {
        return $this->events;
    }
}

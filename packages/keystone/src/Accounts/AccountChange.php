<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Closure;
use SensitiveParameter;

/**
 * @internal
 */
class AccountChange
{
    /**
     * Whether the change ends the account's sessions, which moves its credential epoch.
     */
    protected bool $movesEpoch = false;

    /**
     * The security events the change records once it commits.
     *
     * @var list<Closure(Recorder): void>
     */
    protected array $events = [];

    /**
     * Create the change to the account, whose alerts go to the given addresses.
     *
     * @param  list<string>  $recipients
     */
    public function __construct(
        protected KeystoneUser $account,
        protected array $recipients,
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
            recipients: $this->recipients,
        );
    }

    /**
     * Replace the credential's secret with its rehash, which ends nothing, refusing when another write changed the secret since it was loaded.
     *
     * Every write encrypts with a fresh IV, so a ciphertext that differs from the loaded one means another write got there first.
     *
     * @throws AccountChangeRefusedException
     */
    public function rehash(UserCredential $credential, #[SensitiveParameter] string $secret): void
    {
        $updated = UserCredential::query()
            ->whereKey($credential->getKey())
            ->where('secret', $credential->getRawOriginal('secret'))
            ->update(['secret' => $credential->encryptSecret($secret)]);

        if ($updated === 0) {
            throw AccountChangeRefusedException::secretChanged();
        }
    }

    /**
     * Determine if the change ends the account's sessions, so its credential epoch moves.
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

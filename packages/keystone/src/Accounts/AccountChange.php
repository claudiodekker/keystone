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
     * End every session of the account, for the operator who asked, and alert its owner.
     */
    public function endSessions(string $operator): void
    {
        $this->terminateSessions($operator, $this->recipients);
    }

    /**
     * End every session of the account, for the operator who asked, without alerting its owner.
     */
    public function endSessionsWithoutAlert(string $operator): void
    {
        $this->terminateSessions($operator, recipients: []);
    }

    /**
     * Suspend the account, for the operator who asked, so it can't sign in and its sessions end.
     *
     * @throws AccountChangeRefusedException
     */
    public function suspend(string $operator): void
    {
        $id = $this->account->getAuthIdentifier();

        if (Suspension::isSuspended($id)) {
            throw AccountChangeRefusedException::alreadySuspended($id);
        }

        Suspension::suspend($id);

        $this->movesEpoch = true;

        $this->recordForOperator(SecurityEventType::ACCOUNT_SUSPENDED, $operator, $this->recipients);
    }

    /**
     * Lift the account's suspension, for the operator who asked, so it signs in again with the credentials it already had.
     *
     * @throws AccountChangeRefusedException
     */
    public function unsuspend(string $operator): void
    {
        $id = $this->account->getAuthIdentifier();

        if (! Suspension::isSuspended($id)) {
            throw AccountChangeRefusedException::notSuspended($id);
        }

        Suspension::lift($id);

        $this->recordForOperator(SecurityEventType::ACCOUNT_UNSUSPENDED, $operator, $this->recipients);
    }

    /**
     * Replace the credential's secret with its rehash, which ends nothing, refusing when another write changed the secret since it was loaded.
     *
     * @throws AccountChangeRefusedException
     */
    public function rehash(UserCredential $credential, #[SensitiveParameter] string $secret): void
    {
        $this->replaceSecret($credential, $secret);
    }

    /**
     * Advance the credential's secret to the one its proof carries, which ends nothing, refusing when another write changed the secret since it was loaded.
     *
     * @throws AccountChangeRefusedException
     */
    public function advance(UserCredential $credential, #[SensitiveParameter] string $secret): void
    {
        $this->replaceSecret($credential, $secret);
    }

    /**
     * End every session of the account, for the operator who asked, alerting the given addresses.
     *
     * @param  list<string>  $recipients
     */
    protected function terminateSessions(string $operator, array $recipients): void
    {
        $this->movesEpoch = true;

        $this->recordForOperator(SecurityEventType::SESSIONS_TERMINATED, $operator, $recipients);
    }

    /**
     * Replace the credential's secret, unless another write changed it since it was loaded.
     *
     * Every write encrypts with a fresh IV, so a ciphertext that differs from the loaded one means another write got there first.
     *
     * @throws AccountChangeRefusedException
     */
    protected function replaceSecret(UserCredential $credential, #[SensitiveParameter] string $secret): void
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
     * Record the event of the type for the operator who caused it once the change commits, alerting the given addresses.
     *
     * @param  list<string>  $recipients
     */
    protected function recordForOperator(SecurityEventType $type, string $operator, array $recipients): void
    {
        $this->events[] = fn (Recorder $recorder) => $recorder->record(
            $type,
            account: $this->account,
            operator: $operator,
            recipients: $recipients,
        );
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

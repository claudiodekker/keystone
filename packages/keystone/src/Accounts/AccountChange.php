<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCodeType;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Closure;
use Illuminate\Support\Facades\Date;
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
     * End every session of the account and forget its known devices, for the operator who asked, and alert its owner.
     */
    public function endSessions(string $operator): void
    {
        $this->terminateSessions($operator, $this->recipients);
    }

    /**
     * End every session of the account and forget its known devices, for the operator who asked, without alerting its owner.
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
     * Advance the credential's secret to the given one, which ends nothing, refusing when another write changed the secret since it was loaded.
     *
     * @throws AccountChangeRefusedException
     */
    public function advance(UserCredential $credential, #[SensitiveParameter] string $secret): void
    {
        $this->replaceSecret($credential, $secret);
    }

    /**
     * Give the account a new credential of the type holding the secret, enrolled on the surface, which ends nothing, and alert its owner.
     */
    public function addCredential(CredentialType $type, #[SensitiveParameter] string $secret, Surface $surface): UserCredential
    {
        $credential = UserCredential::query()->forceCreate([
            'user_id' => $this->account->getAuthIdentifier(),
            'type' => $type->name(),
            'secret' => $secret,
        ]);

        $this->events[] = fn (Recorder $recorder) => $recorder->record(
            SecurityEventType::CREDENTIAL_ADDED,
            account: $this->account,
            surface: $surface,
            credentialType: $type,
            credential: $credential->getKey(),
            recipients: $this->recipients,
        );

        return $credential;
    }

    /**
     * Give the account its first set of recovery codes, stored as their digests, which ends nothing and alerts nobody, refusing when it already holds codes.
     *
     * @param  list<RecoveryCode>  $codes
     *
     * @throws AccountChangeRefusedException
     */
    public function saveRecoveryCodes(array $codes): void
    {
        $id = $this->account->getAuthIdentifier();

        if (UserRecoveryCode::query()->where('user_id', $id)->exists()) {
            throw AccountChangeRefusedException::recoveryCodesHeld();
        }

        $now = Date::now();

        UserRecoveryCode::query()->insert(array_map(fn (RecoveryCode $code) => [
            'user_id' => $id,
            'digest' => $code->digest(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes));

        $this->events[] = fn (Recorder $recorder) => $recorder->record(
            SecurityEventType::RECOVERY_CODES_GENERATED,
            account: $this->account,
            surface: Surface::ENROLLMENT,
            credentialType: new RecoveryCodeType,
            recipients: [],
        );
    }

    /**
     * Determine if the code is the only unspent recovery code the account holds.
     */
    public function isLastRecoveryCode(UserRecoveryCode $code): bool
    {
        $held = UserRecoveryCode::query()
            ->where('user_id', $this->account->getAuthIdentifier())
            ->pluck($code->getKeyName());

        return $held->count() === 1 && $held->contains($code->getKey());
    }

    /**
     * Spend the recovery code that answered the challenge, which ends nothing, refusing when another answer spent it first.
     *
     * @throws AccountChangeRefusedException
     */
    public function spendRecoveryCode(UserRecoveryCode $code): void
    {
        $deleted = UserRecoveryCode::query()
            ->whereKey($code->getKey())
            ->where('user_id', $this->account->getAuthIdentifier())
            ->delete();

        if ($deleted === 0) {
            throw AccountChangeRefusedException::recoveryCodeSpent();
        }

        $this->events[] = fn (Recorder $recorder) => $recorder->record(
            SecurityEventType::RECOVERY_CODE_USED,
            account: $this->account,
            surface: Surface::CHALLENGE,
            credentialType: new RecoveryCodeType,
            recipients: $this->recipients,
        );
    }

    /**
     * End every session of the account and forget its known devices, for the operator who asked, alerting the given addresses.
     *
     * @param  list<string>  $recipients
     */
    protected function terminateSessions(string $operator, array $recipients): void
    {
        UserKnownDevice::query()
            ->where('user_id', $this->account->getAuthIdentifier())
            ->delete();

        $this->movesEpoch = true;

        $this->recordForOperator(SecurityEventType::SESSIONS_TERMINATED, $operator, $recipients);
    }

    /**
     * Replace the credential's secret, refusing when another write changed it since it was loaded.
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

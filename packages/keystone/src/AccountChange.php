<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\AlreadySuspended;
use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\LastSecondFactor;
use ClaudioDekker\Keystone\Exceptions\LastSignInCredential;
use ClaudioDekker\Keystone\Exceptions\NotSuspended;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
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
     * Whether the mover's own session must take a new id once the change commits.
     */
    protected bool $rotatesSession = false;

    /**
     * Whether the rows of the account's other sessions must be deleted once the mover's own session carries its new id.
     */
    protected bool $dropsSessions = false;

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
        protected RecoveryCodes $recoveryCodes,
        protected KnownDevices $knownDevices,
        protected ?AccountSessions $sessions,
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
     * Store the enrolled credential on the account and record it in the flow.
     *
     * A credential that replaces its type takes the place of every credential of the type the account holds, disabled or not, and ends the account's other sessions when it held one.
     *
     * A replacing credential the account already holds was stored by the same answer arriving twice, so it is not stored, recorded or replaced again.
     */
    public function enroll(CredentialType $type, EnrolledCredential $enrolled, Flow $flow): void
    {
        if ($enrolled->replacesExisting && $this->holds($type, $enrolled)) {
            return;
        }

        if ($enrolled->replacesExisting && $this->credentials->deleteOfType($this->account->getKey(), $type->name()) > 0) {
            $this->endSessions();
        }

        $id = $this->addCredential($type, identifier: $enrolled->identifier, secret: $enrolled->secret, label: $enrolled->label);

        $this->rotatesSession = true;

        $this->record(
            SecurityEventType::CREDENTIAL_ADDED,
            flow: $flow->value,
            credentialType: $type->name(),
            credential: new StoredCredential($id, identifier: null, secret: null, label: $enrolled->label),
        );
    }

    /**
     * Remove the account's credential with the id, of any type, disabled or not, ending its other sessions.
     *
     * @throws LastSignInCredential
     * @throws LastSecondFactor
     */
    public function removeCredential(int $credentialId): bool
    {
        $accountId = $this->account->getKey();
        $credential = $this->credentials->find($credentialId, $accountId);

        if ($credential === null) {
            return false;
        }

        if ($this->isLastSignInCredential($credentialId)) {
            throw new LastSignInCredential;
        }

        if (config('keystone.require_second_factor') === true && $this->isLastSecondFactor($credentialId)) {
            throw new LastSecondFactor;
        }

        $this->credentials->delete($credentialId, $accountId);

        $this->endSessions();

        $this->record(
            SecurityEventType::CREDENTIAL_REMOVED,
            credentialType: $credential['type'],
            credential: new StoredCredential($credentialId, identifier: null, secret: null, label: $credential['label']),
        );

        return true;
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
     * Stamp the usable credential of the type as used now, or answer false when it is gone or disabled.
     */
    public function stampLastUse(StoredCredential $credential, string $type): bool
    {
        return $this->credentials->stampLastUse($credential->id, type: $type);
    }

    /**
     * Spend the account's recovery code the typed one matches, recording its use in the flow, unless it is the last one and must be kept.
     *
     * @throws LastRecoveryCode
     */
    public function spendRecoveryCode(#[\SensitiveParameter] string $typed, Flow $flow, bool $keepLast): bool
    {
        $accountId = $this->account->getKey();
        $id = $this->recoveryCodes->find($accountId, $typed);

        if ($id === null) {
            return false;
        }

        $remaining = $this->recoveryCodes->remaining($accountId);

        if ($keepLast && $remaining === 1) {
            throw new LastRecoveryCode;
        }

        if (! $this->recoveryCodes->spend($id)) {
            return false;
        }

        $this->record(SecurityEventType::RECOVERY_CODE_USED, flow: $flow->value, credentialType: CredentialTypes::RECOVERY_CODE);

        return true;
    }

    /**
     * Store the set as the account's recovery codes in place of any it holds, ending its other sessions when it replaces a set.
     *
     * @param  list<string>  $codes
     */
    public function commitRecoveryCodes(#[\SensitiveParameter] array $codes, Flow $flow): void
    {
        $accountId = $this->account->getKey();
        $replaces = $this->recoveryCodes->hasRemaining($accountId);

        $this->recoveryCodes->replace($accountId, $codes);

        if ($replaces) {
            $this->endSessions();
        }

        $this->record(SecurityEventType::RECOVERY_CODES_GENERATED, alert: $replaces, flow: $flow->value, credentialType: CredentialTypes::RECOVERY_CODE);
    }

    /**
     * End every session of the account but the mover's own.
     */
    public function endSessions(): void
    {
        $this->movesEpoch = true;
    }

    /**
     * End every session of the account but the mover's own, deleting their rows where the session driver keeps them, and record it.
     */
    public function signOutOthers(): void
    {
        $this->endSessions();

        $this->dropsSessions = true;

        $this->record(SecurityEventType::SESSIONS_REVOKED_OTHERS);
    }

    /**
     * Delete the rows of the sessions the change signed out, once the mover's own session carries the id it will be stored under.
     */
    public function dropSessions(): void
    {
        if ($this->dropsSessions) {
            $this->sessions?->deleteOthers();
        }
    }

    /**
     * Forget every device the account signed in from, so each one's next sign-in alerts.
     */
    public function forgetDevices(): void
    {
        $this->knownDevices->forget();
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
    public function record(
        SecurityEventType $type,
        Actor $actor = Actor::USER,
        ?string $operator = null,
        bool $alert = true,
        ?string $flow = null,
        ?string $credentialType = null,
        ?StoredCredential $credential = null,
    ): void {
        $this->events[] = fn (SecurityEventRecorder $recorder) => $recorder->record(
            $type,
            account: $this->account,
            actor: $actor,
            flow: $flow,
            credentialType: $credentialType,
            credential: $credential,
            operator: $operator,
            recipients: $this->recipients,
            alert: $alert,
        );
    }

    /**
     * Determine if the credential is the account's only one that can sign in.
     */
    protected function isLastSignInCredential(int $credentialId): bool
    {
        return $this->credentials->lockServing($this->account->getKey(), Surface::SIGN_IN) === [$credentialId];
    }

    /**
     * Determine if the credential is the account's only second factor.
     */
    protected function isLastSecondFactor(int $credentialId): bool
    {
        return $this->credentials->lockServing($this->account->getKey(), Surface::CHALLENGE) === [$credentialId];
    }

    /**
     * Determine if the locked account is still on the credential epoch.
     */
    public function isOnEpoch(int $epoch): bool
    {
        return (int) $this->account->getRawOriginal('credential_epoch') === $epoch;
    }

    /**
     * Determine if the change must move the account's credential epoch.
     */
    public function movesEpoch(): bool
    {
        return $this->movesEpoch;
    }

    /**
     * Determine if the account holds a usable credential of the type with the secret of the enrolled one.
     */
    protected function holds(CredentialType $type, EnrolledCredential $enrolled): bool
    {
        if ($enrolled->secret === null) {
            return false;
        }

        $held = $this->credentials->ofType($this->account->getKey(), $type->name());

        return array_any($held, fn (StoredCredential $credential) => $credential->secret !== null && hash_equals($credential->secret, $enrolled->secret));
    }

    /**
     * Determine if the mover's own session must take a new id once the change commits.
     */
    public function rotatesSession(): bool
    {
        return $this->rotatesSession;
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

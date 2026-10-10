<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\AddressTaken;
use ClaudioDekker\Keystone\Exceptions\AlreadySuspended;
use ClaudioDekker\Keystone\Exceptions\CurrentSession;
use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\LastSecondFactor;
use ClaudioDekker\Keystone\Exceptions\LastSignInCredential;
use ClaudioDekker\Keystone\Exceptions\NotSuspended;
use ClaudioDekker\Keystone\Exceptions\Superseded;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * The sessions the change revoked, whose rows must be deleted once it commits.
     *
     * @var list<ListedSession>
     */
    protected array $revokedSessions = [];

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
     * Give the account the address, as Keystone stores it, verified and as its primary address.
     *
     * @throws AddressTaken
     */
    public function addVerifiedAddress(string $address): void
    {
        $address = Addresses::normalize($address);
        $now = Date::now();

        try {
            $this->account->getConnection()->table('user_emails')->insert([
                'user_id' => $this->account->getKey(),
                'address' => $address,
                'verified_address' => $address,
                'verified_at' => $now,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new AddressTaken;
        }
    }

    /**
     * Give the account the address, as Keystone stores it, unverified and as its primary address, counting as verified while the account holds no verified one.
     *
     * @throws AddressTaken
     */
    public function addUnverifiedAddress(string $address): void
    {
        $address = Addresses::normalize($address);
        $now = Date::now();

        try {
            $this->account->getConnection()->table('user_emails')->insert([
                'user_id' => $this->account->getKey(),
                'address' => $address,
                'verified_address' => $address,
                'verified_at' => null,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new AddressTaken;
        }
    }

    /**
     * Remove the address from the account, as Keystone stores it, moving the primary to another address when it was the primary one.
     */
    public function loseAddress(string $address): void
    {
        $address = Addresses::normalize($address);
        $emails = fn () => $this->account->getConnection()->table('user_emails')->where('user_id', $this->account->getKey());

        $wasPrimary = $emails()->where('address', $address)->where('is_primary', true)->exists();

        $emails()->where('address', $address)->delete();

        if (! $wasPrimary) {
            return;
        }

        $fallback = $emails()->orderByRaw('case when verified_at is null then 1 else 0 end')->orderBy('id')->value('id');

        if ($fallback !== null) {
            $emails()->where('id', $fallback)->update(['is_primary' => true, 'updated_at' => Date::now()]);
        }
    }

    /**
     * Store the enrolled credential on the account and record in the flow whether it was added or replaced what the account held.
     *
     * A credential that replaces its type takes the place of every credential of the type the account holds, disabled or not.
     *
     * A replacing credential the account already holds was stored by the same answer arriving twice, so it is not stored, recorded or replaced again.
     *
     * @param  list<StoredCredential>  $provedAgainst
     *
     * @throws Superseded
     */
    public function enroll(CredentialType $type, EnrolledCredential $enrolled, Flow $flow, array $provedAgainst): SettingsEnrollmentResult
    {
        $stored = $enrolled->replacesExisting ? $this->storedBySameAnswer($type, $enrolled) : null;

        if ($stored !== null) {
            $others = array_filter($provedAgainst, fn (StoredCredential $credential) => $credential->id !== $stored->id);

            return $others === [] ? SettingsEnrollmentResult::ADDED : SettingsEnrollmentResult::REPLACED;
        }

        if ($enrolled->replacesExisting && ! $this->stillHolds($type, $provedAgainst)) {
            throw new Superseded;
        }

        if ($enrolled->replacesExisting) {
            $this->credentials->deleteOfType($this->account->getKey(), $type->name());
        }

        $replaced = $enrolled->replacesExisting && $provedAgainst !== [];

        if ($replaced) {
            $this->endSessions();
        }

        $id = $this->addCredential($type, identifier: $enrolled->identifier, secret: $enrolled->secret, label: $enrolled->label);

        $this->rotatesSession = true;

        $this->record(
            $replaced ? SecurityEventType::CREDENTIAL_REPLACED : SecurityEventType::CREDENTIAL_ADDED,
            flow: $flow->value,
            credentialType: $type->name(),
            credential: new StoredCredential($id, identifier: null, secret: null, label: $enrolled->label),
        );

        return $replaced ? SettingsEnrollmentResult::REPLACED : SettingsEnrollmentResult::ADDED;
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

        $refusal = $this->credentials->removalRefusal($accountId, $credentialId);

        if ($refusal !== null) {
            throw $refusal;
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
     * Revoke the account's live session the handle names, forgetting the remember token it stored, without ending any other session, and record it.
     *
     * @throws CurrentSession
     */
    public function revokeSession(string $handle): bool
    {
        if ($this->sessions === null) {
            return false;
        }

        $session = $this->sessions->find($handle);

        if ($session === null) {
            return false;
        }

        if ($session->current) {
            throw new CurrentSession;
        }

        if ($session->rememberTokenId !== null) {
            (new RememberTokens($this->account))->forget($this->account->getKey(), $session->rememberTokenId);
        }

        $this->revokedSessions[] = $session;

        $this->record(SecurityEventType::SESSION_REVOKED, context: $this->sessions->contextOf($session));

        return true;
    }

    /**
     * Delete the rows of the sessions the change signed out, once the mover's own session carries the id it will be stored under.
     */
    public function dropSessions(): void
    {
        if ($this->dropsSessions) {
            $this->sessions?->deleteOthers();
        }

        foreach ($this->revokedSessions as $session) {
            $this->sessions?->delete($session);
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
     * Record the event about the account once the change commits, alerting the given recipients, or those read before it, unless suppressed.
     *
     * @param  list<string>|null  $recipients
     */
    public function record(
        SecurityEventType $type,
        Actor $actor = Actor::USER,
        ?string $operator = null,
        bool $alert = true,
        ?string $flow = null,
        ?string $credentialType = null,
        ?StoredCredential $credential = null,
        ?RequestContext $context = null,
        ?array $recipients = null,
    ): void {
        $this->events[] = fn (SecurityEventRecorder $recorder) => $recorder->record(
            $type,
            account: $this->account,
            actor: $actor,
            flow: $flow,
            credentialType: $credentialType,
            credential: $credential,
            operator: $operator,
            recipients: $recipients ?? $this->recipients,
            alert: $alert,
            context: $context,
        );
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
     * Get the account's usable credential of the type holding the enrolled one's secret, which the same answer stored when it arrived before.
     */
    protected function storedBySameAnswer(CredentialType $type, EnrolledCredential $enrolled): ?StoredCredential
    {
        if ($enrolled->secret === null) {
            return null;
        }

        $held = $this->credentials->ofType($this->account->getKey(), $type->name());

        return array_find($held, fn (StoredCredential $credential) => $credential->secret !== null && hash_equals($credential->secret, $enrolled->secret));
    }

    /**
     * Determine if the account's usable credentials of the type are still exactly the ones listed.
     *
     * @param  list<StoredCredential>  $credentials
     */
    protected function stillHolds(CredentialType $type, array $credentials): bool
    {
        $held = $this->credentials->ofType($this->account->getKey(), $type->name());

        return array_column($held, 'id') === array_column($credentials, 'id');
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

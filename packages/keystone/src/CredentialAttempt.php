<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Timebox;
use Throwable;

/**
 * @internal
 */
abstract class CredentialAttempt
{
    /**
     * The least time an attempt takes unless it is proven.
     */
    public const int TIMING_FLOOR_MICROSECONDS = 300_000;

    /**
     * What concludes a step once its proof is accepted.
     */
    protected AcceptedProof $accepted;

    /**
     * Create a new credential attempt instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected RateLimiter $limiter,
        protected Timebox $timebox = new Timebox,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        $this->accepted = new AcceptedProof($recorder, $limiter);
    }

    /**
     * Let the type verify the input on the surface against the subject's usable credentials and its ceremony, if any, turning any failure into a rejection.
     *
     * The proof comes back with the subject's credential it names, if any. A failure gives the taken attempt back.
     *
     * @param  (Model&KeystoneUser)|null  $account
     * @param  array<string, mixed>  $input
     * @return array{Proof, ?StoredCredential}
     */
    protected function prove(
        Surface $surface,
        CredentialType $type,
        ?Model $account,
        #[\SensitiveParameter] array $input,
        ?TakenAttempt $taken = null,
        #[\SensitiveParameter] mixed $ceremony = null,
    ): array {
        $credentials = new Credentials($this->guard->userModel());

        try {
            $usable = $account === null ? [] : $credentials->ofType($account->getKey(), $type->name());
            $proof = $type->verify($surface, $input, $usable, $ceremony);
        } catch (Throwable $e) {
            report($e);

            if ($taken !== null) {
                $this->limiter->giveBack($taken);
            }

            return [Proof::rejected('keystone.verify_failed'), null];
        }

        $named = array_filter($usable, fn (StoredCredential $credential) => $credential->id === $proof->credentialId);

        return [$proof, array_values($named)[0] ?? null];
    }

    /**
     * Determine if the proof is proven and names a usable credential the account owns.
     *
     * @phpstan-assert-if-true !null $credential
     */
    protected function owns(Model&KeystoneUser $account, CredentialType $type, Proof $proof, ?StoredCredential $credential): bool
    {
        if (! $proof->proven || $proof->credentialId === null || $credential === null) {
            return false;
        }

        $credentials = new Credentials($this->guard->userModel());
        $owner = $credentials->ownerOf($proof->credentialId, $type->name());

        return $owner !== null && (string) $owner === (string) $account->getKey();
    }

    /**
     * Write what the accepted proof changed and stamp its credential's last use, conclude its step with the pass, then store the secret the proof updated, refusing a proof another one overtook.
     *
     * @template TOutcome of Demand|SudoResult
     *
     * @param  Pass<TOutcome>  $pass
     * @return TOutcome|null what the pass led to, or null for a refused step
     */
    protected function finish(
        Pass $pass,
        Model&KeystoneUser $account,
        Flow $flow,
        CredentialType $type,
        Proof $proof,
        StoredCredential $credential,
        TakenAttempt $taken,
    ): Demand|SudoResult|null {
        if (! $this->markUsed($account, $type, $proof, $credential)) {
            $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.superseded');

            return null;
        }

        $outcome = $this->conclude($pass, $account, $flow, $type, $credential, $taken);

        if ($outcome !== null) {
            $this->storeUpdatedSecret($account, $type, $proof, $credential);
        }

        return $outcome;
    }

    /**
     * Conclude the step with the pass, ending the timing floor early once it stands.
     *
     * @template TOutcome of Demand|SudoResult
     *
     * @param  Pass<TOutcome>  $pass
     * @return TOutcome|null what the pass led to, or null for a barred account
     */
    protected function conclude(
        Pass $pass,
        Model&KeystoneUser $account,
        Flow $flow,
        CredentialType $type,
        ?StoredCredential $credential,
        TakenAttempt $taken,
    ): Demand|SudoResult|null {
        $outcome = $this->accepted->conclude($pass, $account, $flow, $type->name(), $credential, $taken);

        if ($outcome !== null) {
            $this->timebox->returnEarly();
        }

        return $outcome;
    }

    /**
     * Spend the account's recovery code the typed one matches, or refuse a barred account, a code it doesn't hold or the last one while it is kept.
     *
     * @throws LastRecoveryCode
     */
    protected function spendRecoveryCode(Model&KeystoneUser $account, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, bool $keepLast): bool
    {
        try {
            $spent = $this->spendCode($account, $flow, $typed, $keepLast);
        } catch (LastRecoveryCode $e) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.last_recovery_code');

            throw $e;
        }

        if ($spent === null) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.barred');

            return false;
        }

        if (! $spent) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'recovery-code.mismatch');
        }

        return $spent;
    }

    /**
     * Spend the account's recovery code the typed one matches under its row lock, or answer null for a barred account.
     *
     * @throws LastRecoveryCode
     */
    protected function spendCode(Model&KeystoneUser $account, Flow $flow, #[\SensitiveParameter] string $typed, bool $keepLast): ?bool
    {
        $changes = new AccountChanges($this->guard);

        return $changes->change($account, function (AccountChange $change) use ($typed, $flow, $keepLast) {
            if ((new SignInDecision)->isBarred($change->account)) {
                return null;
            }

            return $change->spendRecoveryCode($typed, flow: $flow, keepLast: $keepLast);
        });
    }

    /**
     * Store the secret the proof moved its credential on to, refusing the proof when another proof moved the credential first.
     */
    protected function advance(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential): bool
    {
        if ($proof->advancedSecret === null) {
            return true;
        }

        $changes = new AccountChanges($this->guard);

        return $changes->change($account, function (AccountChange $change) use ($credential, $type, $proof) {
            return $change->advance($credential, type: $type->name(), secret: (string) $proof->advancedSecret);
        });
    }

    /**
     * Store the secret the proof moved its credential on to and stamp the credential as used now, in one locked change, refusing a proof another one overtook or whose credential was removed meanwhile.
     */
    protected function markUsed(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential): bool
    {
        $changes = new AccountChanges($this->guard);

        return $changes->change($account, function (AccountChange $change) use ($credential, $type, $proof) {
            if ($proof->advancedSecret !== null && ! $change->advance($credential, type: $type->name(), secret: (string) $proof->advancedSecret)) {
                return false;
            }

            return $change->stampLastUse($credential, type: $type->name());
        });
    }

    /**
     * Make and store the secret the proof updated in place of the one the type verified, reporting a failure without refusing.
     */
    protected function storeUpdatedSecret(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential): void
    {
        if ($proof->updatedSecret === null) {
            return;
        }

        rescue(function () use ($account, $type, $proof, $credential) {
            $secret = value($proof->updatedSecret);

            $changes = new AccountChanges($this->guard);

            $changes->change($account, function (AccountChange $change) use ($credential, $type, $secret) {
                return $change->rehash($credential, type: $type->name(), secret: $secret);
            });
        });
    }

    /**
     * Record a refused answer for the account in the flow, naming only a credential the account owns.
     */
    protected function recordRejected(
        Model&KeystoneUser $account,
        Flow $flow,
        CredentialType $type,
        ?StoredCredential $credential,
        string $reason,
    ): void {
        $this->recorder->record(
            $flow->rejectionType(),
            account: $account,
            flow: $flow->value,
            credentialType: $type->name(),
            credential: $credential,
            reason: $reason,
        );
    }
}

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
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
     * Create a new credential attempt instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected RateLimiter $limiter,
        protected Timebox $timebox = new Timebox,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Let the type verify the input on the surface against the subject's usable credentials, turning any failure into a rejection.
     *
     * The proof comes back with the subject's credential it names, if any. A failure gives the taken attempt back.
     *
     * @param  (Model&KeystoneUser)|null  $account
     * @param  array<string, mixed>  $input
     * @return array{Proof, ?StoredCredential}
     */
    protected function prove(Surface $surface, CredentialType $type, ?Model $account, #[\SensitiveParameter] array $input, TakenAttempt $taken): array
    {
        $credentials = new Credentials($this->guard->userModel());

        try {
            $usable = $account === null ? [] : $credentials->ofType($account->getKey(), $type->name());
            $proof = $type->verify($surface, $input, $usable);
        } catch (Throwable $e) {
            report($e);
            $this->limiter->giveBack($taken);

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
     * Record a refused proof for the account in the flow, naming only a credential the account owns.
     */
    protected function recordRejected(
        Model&KeystoneUser $account,
        Flow $flow,
        CredentialType $type,
        ?StoredCredential $credential,
        string $reason,
    ): void {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $account,
            flow: $flow->value,
            credentialType: $type->name(),
            credential: $credential,
            reason: $reason,
        );
    }
}

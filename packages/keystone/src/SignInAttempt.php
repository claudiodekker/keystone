<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Actions\RehashCredential;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Timebox;
use LogicException;
use Throwable;

/**
 * @internal
 */
class SignInAttempt
{
    /**
     * The least time a sign-in attempt takes unless it signs in.
     */
    public const int TIMING_FLOOR_MICROSECONDS = 300_000;

    /**
     * Create a new sign-in attempt instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected AccountLookup $lookup,
        protected RateLimiter $limiter,
        protected Timebox $timebox = new Timebox,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
        protected RehashCredential $rehash = new RehashCredential,
    ) {
        //
    }

    /**
     * Prove the input for the named account and sign it in, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     * @return (Model&KeystoneUser)|null
     *
     * @throws Throttled
     */
    public function attempt(?CredentialType $type, string $identifier, #[\SensitiveParameter] array $input): ?Model
    {
        return $this->timebox->call(function (Timebox $timebox) use ($type, $identifier, $input) {
            if ($type === null) {
                return null;
            }

            $flow = Flow::of($this->guard, Surface::SIGN_IN);
            $account = $this->subject($identifier);
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, $identifier);
            [$proof, $credential] = $this->prove($type, $account, $input, $taken);

            if ($account === null) {
                return null;
            }

            if (! $this->owns($account, $type, $proof)) {
                $this->recordRejected(
                    account: $account,
                    type: $type,
                    credential: $credential,
                    reason: $proof->reason ?? 'keystone.foreign_credential',
                );

                return null;
            }

            if ((new SignInDecision)->demand($account) !== Demand::SIGN_IN) {
                $this->recordRejected(
                    account: $account,
                    type: $type,
                    credential: $credential,
                    reason: 'keystone.barred',
                );

                return null;
            }

            try {
                $this->guard->signIn($account);
            } catch (LogicException) {
                $this->recordRejected(
                    account: $account,
                    type: $type,
                    credential: $credential,
                    reason: 'keystone.barred',
                );

                return null;
            }

            $this->storeUpdatedSecret($account, $type, $proof, $credential);
            $this->limiter->giveBack($taken);

            $this->recorder->record(
                SecurityEventType::SIGNED_IN,
                account: $account,
                flow: Surface::SIGN_IN->value,
                credentialType: $type->name(),
                credential: $credential,
            );

            $timebox->returnEarly();

            return $account;
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Let the type verify the input against the subject's usable credentials, turning any failure into a rejection.
     *
     * The proof comes back with the subject's credential it names, if any. A failure gives the taken attempt back.
     *
     * @param  (Model&KeystoneUser)|null  $account
     * @param  array<string, mixed>  $input
     * @return array{Proof, ?StoredCredential}
     */
    protected function prove(CredentialType $type, ?Model $account, #[\SensitiveParameter] array $input, TakenAttempt $taken): array
    {
        $credentials = new Credentials($this->guard->userModel());

        try {
            $usable = $account === null ? [] : $credentials->ofType($account->getKey(), $type->name());
            $proof = $type->verify(Surface::SIGN_IN, $input, $usable);
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
     */
    protected function owns(Model&KeystoneUser $account, CredentialType $type, Proof $proof): bool
    {
        if (! $proof->proven || $proof->credentialId === null) {
            return false;
        }

        $credentials = new Credentials($this->guard->userModel());
        $owner = $credentials->ownerOf($proof->credentialId, $type->name());

        return $owner !== null && (string) $owner === (string) $account->getKey();
    }

    /**
     * Make and store the secret the proof updated in place of the one the type verified, reporting a failure without refusing.
     */
    protected function storeUpdatedSecret(Model&KeystoneUser $account, CredentialType $type, Proof $proof, ?StoredCredential $credential): void
    {
        if ($proof->updatedSecret === null || $credential === null) {
            return;
        }

        rescue(function () use ($account, $type, $proof, $credential) {
            $secret = value($proof->updatedSecret);

            $this->rehash->handle($account, $credential, type: $type->name(), secret: $secret);
        });
    }

    /**
     * Record a refused sign-in for the account, naming only a credential the account owns.
     */
    protected function recordRejected(
        Model&KeystoneUser $account,
        CredentialType $type,
        ?StoredCredential $credential,
        string $reason,
    ): void {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $account,
            flow: Surface::SIGN_IN->value,
            credentialType: $type->name(),
            credential: $credential,
            reason: $reason,
        );
    }

    /**
     * Read the account the identifier names, bypassing every global scope.
     *
     * @return (Model&KeystoneUser)|null
     */
    protected function subject(string $identifier): ?Model
    {
        $id = $this->lookup->handle($identifier);

        if ($id === null) {
            return null;
        }

        /** @var (Model&KeystoneUser)|null */
        return $this->guard->userModel()->newQueryWithoutScopes()->whereKey($id)->first();
    }
}

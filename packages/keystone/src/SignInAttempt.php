<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Closure;
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
        protected Timebox $timebox = new Timebox,
    ) {
        //
    }

    /**
     * Prove the input for the named account and sign it in, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     * @return (Model&KeystoneUser)|null
     */
    public function attempt(?CredentialType $type, string $identifier, #[\SensitiveParameter] array $input): ?Model
    {
        return $this->timebox->call(function (Timebox $timebox) use ($type, $identifier, $input) {
            $account = $type === null ? null : $this->prove($type, $identifier, $input);

            if ($account === null || (new SignInDecision)->demand($account) !== Demand::SIGN_IN) {
                return null;
            }

            try {
                $this->guard->signIn($account);
            } catch (LogicException) {
                return null;
            }

            $timebox->returnEarly();

            return $account;
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Get the named account, as read before verifying, when the proof names one of its credentials.
     *
     * @param  array<string, mixed>  $input
     * @return (Model&KeystoneUser)|null
     */
    protected function prove(CredentialType $type, string $identifier, #[\SensitiveParameter] array $input): ?Model
    {
        $account = $this->subject($identifier);
        $credentials = new Credentials($this->guard->userModel());

        $proof = $this->verify($type, $input, fn () => $account === null ? [] : $credentials->ofType($account->getKey(), $type->name()));

        if ($account === null || ! $proof->proven || $proof->credentialId === null) {
            return null;
        }

        $owner = $credentials->ownerOf($proof->credentialId, $type->name());

        return $owner !== null && (string) $owner === (string) $account->getKey() ? $account : null;
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

    /**
     * Let the type verify the input against the credentials, turning any failure into a rejection.
     *
     * @param  array<string, mixed>  $input
     * @param  Closure(): list<StoredCredential>  $credentials
     */
    protected function verify(CredentialType $type, #[\SensitiveParameter] array $input, Closure $credentials): Proof
    {
        try {
            return $type->verify(Surface::SIGN_IN, $input, $credentials());
        } catch (Throwable $e) {
            report($e);

            return Proof::rejected('keystone.verify_failed');
        }
    }
}

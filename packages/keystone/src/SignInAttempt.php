<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Timebox;

/**
 * @internal
 */
class SignInAttempt extends CredentialAttempt
{
    /**
     * Create a new sign-in attempt instance.
     */
    public function __construct(
        KeystoneGuard $guard,
        protected AccountLookup $lookup,
        RateLimiter $limiter,
        Timebox $timebox = new Timebox,
        SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        parent::__construct($guard, $limiter, $timebox, $recorder);
    }

    /**
     * Prove the input for the named account, then sign it in or hold it for what it owes, remembered on its device when it asked, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     */
    public function attempt(?CredentialType $type, string $identifier, #[\SensitiveParameter] array $input, string $intendedUrl, RememberMe $rememberMe): Demand
    {
        return $this->timebox->call(function () use ($type, $identifier, $input, $intendedUrl, $rememberMe) {
            if ($type === null) {
                return Demand::REFUSE;
            }

            $flow = Flow::of($this->guard, Surface::SIGN_IN);
            $account = $this->subject($identifier);
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, $identifier);
            [$proof, $credential] = $this->prove(Surface::SIGN_IN, $type, $account, $input, $taken);

            if ($account === null) {
                return Demand::REFUSE;
            }

            if (! $this->owns($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: $proof->reason ?? 'keystone.foreign_credential');

                return Demand::REFUSE;
            }

            if ((new SignInDecision)->isBarred($account)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.barred');

                return Demand::REFUSE;
            }

            if (! $this->advance($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.superseded');

                return Demand::REFUSE;
            }

            $passed = (new Entry($this->guard))->afterFirstFactor($account, $type, $intendedUrl, $rememberMe);
            $demand = $this->conclude($passed, $account, $flow, $type, $credential, $taken);

            if ($demand === null) {
                return Demand::REFUSE;
            }

            $this->storeUpdatedSecret($account, $type, $proof, $credential);

            return $demand;
        }, self::TIMING_FLOOR_MICROSECONDS);
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

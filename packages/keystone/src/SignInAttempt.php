<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Timebox;
use LogicException;

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
     * Prove the input for the named account, then sign it in or hold it for what it owes, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     */
    public function attempt(?CredentialType $type, string $identifier, #[\SensitiveParameter] array $input, string $intendedUrl): Demand
    {
        return $this->timebox->call(function (Timebox $timebox) use ($type, $identifier, $input, $intendedUrl) {
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

            $demand = (new SignInDecision)->demand($account, $type);

            if ($demand === Demand::REFUSE) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.barred');

                return Demand::REFUSE;
            }

            if (! $this->advance($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.superseded');

                return Demand::REFUSE;
            }

            try {
                $this->enter($account, $type, $demand, $intendedUrl);
            } catch (LogicException) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.barred');

                return Demand::REFUSE;
            }

            $this->storeUpdatedSecret($account, $type, $proof, $credential);
            $this->limiter->giveBack($taken);

            $this->recorder->record(
                $demand === Demand::CHALLENGE ? SecurityEventType::SIGN_IN_HELD : SecurityEventType::SIGNED_IN,
                account: $account,
                flow: $flow->value,
                credentialType: $type->name(),
                credential: $credential,
                reason: $demand === Demand::CHALLENGE ? 'keystone.challenge' : null,
            );

            $timebox->returnEarly();

            return $demand;
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Sign the account in, or hold its sign-in at the challenge, as the decision demands.
     *
     * @throws LogicException
     */
    protected function enter(Model&KeystoneUser $account, CredentialType $type, Demand $demand, string $intendedUrl): void
    {
        if ($demand === Demand::CHALLENGE) {
            $this->guard->hold($account, firstFactor: $type->name(), stage: PendingStage::CHALLENGE, intendedUrl: $intendedUrl);

            return;
        }

        $this->guard->signIn($account);
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

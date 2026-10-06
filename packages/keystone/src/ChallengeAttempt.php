<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class ChallengeAttempt extends CredentialAttempt
{
    /**
     * Prove the answer to the pending sign-in's challenge, then complete the sign-in or hold it for the enrollment the account still owes, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     * @throws LastRecoveryCode
     */
    public function attempt(PendingSignIn $pending, CredentialType $type, #[\SensitiveParameter] array $input): Demand
    {
        return $this->timebox->call(function () use ($pending, $type, $input) {
            $account = $pending->account;
            $flow = Flow::of($this->guard, Surface::CHALLENGE);
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');
            $demand = $this->owed($pending);

            if ($type instanceof RecoveryCodeType) {
                return $this->spendRecoveryCode($pending, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken, $demand);
            }

            [$proof, $credential] = $type->name() === $pending->firstFactor
                ? [Proof::rejected('keystone.first_factor'), null]
                : $this->prove(Surface::CHALLENGE, $type, $account, $input, $taken);

            if (! $this->owns($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: $proof->reason ?? 'keystone.foreign_credential');

                return Demand::REFUSE;
            }

            $entered = $this->finish(
                account: $account,
                flow: $flow,
                type: $type,
                proof: $proof,
                credential: $credential,
                taken: $taken,
                enter: fn () => $this->enter($pending, $demand),
                recorded: $this->recorded($demand),
                reason: $this->reason($demand),
            );

            return $entered ? $demand : Demand::REFUSE;
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Spend the recovery code of the pending sign-in's account the typed one matches and complete the sign-in, refusing a barred account and the last code while the gate keeps it.
     *
     * @throws LastRecoveryCode
     */
    protected function spendRecoveryCode(PendingSignIn $pending, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken, Demand $demand): Demand
    {
        $account = $pending->account;
        $changes = new AccountChanges($this->guard);

        try {
            $spent = $changes->change($account, function (AccountChange $change) use ($typed, $flow) {
                if ((new SignInDecision)->isBarred($change->account)) {
                    return null;
                }

                return $change->spendRecoveryCode($typed, flow: $flow, keepLast: config()->boolean('keystone.require_recovery_codes'));
            });
        } catch (LastRecoveryCode $e) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.last_recovery_code');

            throw $e;
        }

        if ($spent === null) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.barred');

            return Demand::REFUSE;
        }

        if (! $spent) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'recovery-code.mismatch');

            return Demand::REFUSE;
        }

        $entered = $this->complete(
            account: $account,
            flow: $flow,
            type: $type,
            credential: null,
            taken: $taken,
            enter: fn () => $this->enter($pending, $demand),
            recorded: $this->recorded($demand),
            reason: $this->reason($demand),
        );

        return $entered ? $demand : Demand::REFUSE;
    }

    /**
     * Decide what passing the challenge leads to: the enrollment the account still owes, or the sign-in.
     */
    protected function owed(PendingSignIn $pending): Demand
    {
        return (new SignInDecision)->owesEnrollment($pending->account) ? Demand::ENROLLMENT : Demand::SIGN_IN;
    }

    /**
     * Sign the account in, returning whether the browser was a known device of it, or hold its pending sign-in on at the enrollment it still owes.
     *
     * Either way the challenge is passed, so the pending challenge its hold opened is forgotten.
     */
    protected function enter(PendingSignIn $pending, Demand $demand): ?bool
    {
        $knownDevice = $this->pass($pending->account, $demand);

        if ($pending->pendingChallenge !== null) {
            (new PendingChallenges($pending->account))->forget($pending->pendingChallenge);
        }

        return $knownDevice;
    }

    /**
     * Move the pending sign-in past its challenge: on to the enrollment the account still owes, or into a signed-in session.
     */
    protected function pass(Model&KeystoneUser $account, Demand $demand): ?bool
    {
        if ($demand === Demand::ENROLLMENT) {
            $this->guard->passSecondFactor();

            return null;
        }

        return $this->guard->signIn($account);
    }

    /**
     * Get the event a passed challenge records.
     */
    protected function recorded(Demand $demand): SecurityEventType
    {
        return $demand === Demand::ENROLLMENT ? SecurityEventType::SIGN_IN_HELD : SecurityEventType::SIGNED_IN;
    }

    /**
     * Get the reason a passed challenge records.
     */
    protected function reason(Demand $demand): ?string
    {
        return $demand === Demand::ENROLLMENT ? 'keystone.enrollment' : null;
    }
}

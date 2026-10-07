<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;

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
                return $this->answerWithRecoveryCode($pending, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken, $demand);
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
     * Spend the recovery code of the pending sign-in's account the typed one matches and complete the sign-in, keeping the last code while codes are required.
     *
     * @throws LastRecoveryCode
     */
    protected function answerWithRecoveryCode(PendingSignIn $pending, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken, Demand $demand): Demand
    {
        $entered = $this->spendRecoveryCode(
            account: $pending->account,
            flow: $flow,
            type: $type,
            typed: $typed,
            taken: $taken,
            enter: fn () => $this->enter($pending, $demand),
            recorded: $this->recorded($demand),
            keepLast: config()->boolean('keystone.require_recovery_codes'),
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
     * Move the pending sign-in past its challenge, returning whether a signed-in browser was a known device, then forget the pending challenge its hold opened.
     */
    protected function enter(PendingSignIn $pending, Demand $demand): ?bool
    {
        $knownDevice = $this->pass($pending, $demand);

        if ($pending->pendingChallengeId !== null) {
            rescue(fn () => (new PendingChallenges($pending->account))->forget($pending->pendingChallengeId));
        }

        return $knownDevice;
    }

    /**
     * Move the pending sign-in past its challenge: on to the enrollment the account still owes, or into a signed-in session.
     */
    protected function pass(PendingSignIn $pending, Demand $demand): ?bool
    {
        if ($demand === Demand::ENROLLMENT) {
            $this->guard->passSecondFactor();

            return null;
        }

        return $this->guard->signIn($pending->account, $pending->rememberMe);
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

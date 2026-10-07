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

            if ($type instanceof RecoveryCodeType) {
                return $this->answerWithRecoveryCode($pending, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken);
            }

            [$proof, $credential] = $type->name() === $pending->firstFactor
                ? [Proof::rejected('keystone.first_factor'), null]
                : $this->prove(Surface::CHALLENGE, $type, $account, $input, $taken);

            if (! $this->owns($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: $proof->reason ?? 'keystone.foreign_credential');

                return Demand::REFUSE;
            }

            if (! $this->advance($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.superseded');

                return Demand::REFUSE;
            }

            $passed = (new Entry($this->guard))->afterChallenge($pending);
            $demand = $this->conclude($passed, $account, $flow, $type, $credential, $taken);

            if ($demand === null) {
                return Demand::REFUSE;
            }

            $this->storeUpdatedSecret($account, $type, $proof, $credential);

            return $demand;
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Spend the recovery code of the pending sign-in's account the typed one matches and complete the sign-in, keeping the last code while codes are required.
     *
     * @throws LastRecoveryCode
     */
    protected function answerWithRecoveryCode(PendingSignIn $pending, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken): Demand
    {
        if (! $this->spendRecoveryCode($pending->account, $flow, $type, $typed, keepLast: config()->boolean('keystone.require_recovery_codes'))) {
            return Demand::REFUSE;
        }

        $passed = (new Entry($this->guard))->afterChallenge($pending);

        return $this->conclude($passed, $pending->account, $flow, $type, credential: null, taken: $taken) ?? Demand::REFUSE;
    }
}

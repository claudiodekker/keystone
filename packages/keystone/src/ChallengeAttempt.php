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
     * Prove the answer to the pending sign-in's challenge and complete the sign-in, or refuse inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     * @throws LastRecoveryCode
     */
    public function attempt(PendingSignIn $pending, CredentialType $type, #[\SensitiveParameter] array $input): bool
    {
        return $this->timebox->call(function () use ($pending, $type, $input) {
            $account = $pending->account;
            $flow = Flow::of($this->guard, Surface::CHALLENGE);
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');

            if ($type instanceof RecoveryCodeType) {
                return $this->spendRecoveryCode($account, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken);
            }

            [$proof, $credential] = $type->name() === $pending->firstFactor
                ? [Proof::rejected('keystone.first_factor'), null]
                : $this->prove(Surface::CHALLENGE, $type, $account, $input, $taken);

            if (! $this->owns($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: $proof->reason ?? 'keystone.foreign_credential');

                return false;
            }

            return $this->finish(
                account: $account,
                flow: $flow,
                type: $type,
                proof: $proof,
                credential: $credential,
                taken: $taken,
                enter: function () use ($account) {
                    $this->guard->signIn($account);
                },
                recorded: SecurityEventType::SIGNED_IN,
            );
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Spend the account's recovery code the typed one matches and complete the sign-in, refusing the last code while the gate keeps it.
     *
     * @throws LastRecoveryCode
     */
    protected function spendRecoveryCode(Model&KeystoneUser $account, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken): bool
    {
        $changes = new AccountChanges($this->guard);

        try {
            $spent = $changes->change($account, function (AccountChange $change) use ($typed, $flow) {
                return $change->spendRecoveryCode($typed, flow: $flow, keepLast: config()->boolean('keystone.require_recovery_codes'));
            });
        } catch (LastRecoveryCode $e) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.last_recovery_code');

            throw $e;
        }

        if (! $spent) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'recovery-code.mismatch');

            return false;
        }

        return $this->complete(
            account: $account,
            flow: $flow,
            type: $type,
            credential: null,
            taken: $taken,
            enter: function () use ($account) {
                $this->guard->signIn($account);
            },
            recorded: SecurityEventType::SIGNED_IN,
        );
    }
}

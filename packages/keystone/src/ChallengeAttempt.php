<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;

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
     */
    public function attempt(PendingSignIn $pending, CredentialType $type, #[\SensitiveParameter] array $input): bool
    {
        return $this->timebox->call(function () use ($pending, $type, $input) {
            $account = $pending->account;
            $flow = Flow::of($this->guard, Surface::CHALLENGE);
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');

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
}

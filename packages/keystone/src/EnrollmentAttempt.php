<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Exceptions\Superseded;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @internal
 */
class EnrollmentAttempt extends CredentialAttempt
{
    /**
     * Verify the answer to the type's enrollment ceremony and store the new credential, then sign in or keep the sign-in held for what the account still owes.
     *
     * @param  array<string, mixed>  $input
     */
    public function attempt(PendingSignIn $pending, CredentialType $type, #[\SensitiveParameter] array $input, #[\SensitiveParameter] mixed $ceremony): Demand
    {
        $account = $pending->account;
        [$proof] = $this->prove(Surface::ENROLLMENT, $type, $account, $input, ceremony: $ceremony);

        if ($proof->enrolled === null) {
            $this->recordRejected($account, Flow::ENROLLMENT, $type, credential: null, reason: $proof->reason ?? 'keystone.not_enrolled');

            return Demand::REFUSE;
        }

        $refusal = $this->store($pending, $type, $proof->enrolled);

        if ($refusal !== null) {
            $this->recordRejected($account, Flow::ENROLLMENT, $type, credential: null, reason: $refusal);

            return Demand::REFUSE;
        }

        (new EnrollmentCeremonies($this->guard))->close($type);

        return $this->accepted->conclude(new SecondFactorEnrollmentEntry($this->guard, $this->recorder), $account, Flow::ENROLLMENT, $type->name()) ?? Demand::REFUSE;
    }

    /**
     * Store the enrolled credential on the account and record it, or give why not once the account is locked: barred, off the pending sign-in's epoch, or holding a second factor already.
     */
    protected function store(PendingSignIn $pending, CredentialType $type, EnrolledCredential $enrolled): ?string
    {
        $changes = new AccountChanges($this->guard, $this->recorder);

        try {
            return $changes->change($pending->account, function (AccountChange $change) use ($type, $enrolled) {
                if ((new Credentials($change->account))->holdsSecondFactor($change->account->getKey())) {
                    return 'keystone.second_factor_held';
                }

                $id = $change->addCredential($type, identifier: $enrolled->identifier, secret: $enrolled->secret, label: $enrolled->label);

                $change->record(
                    SecurityEventType::CREDENTIAL_ADDED,
                    flow: Flow::ENROLLMENT->value,
                    credentialType: $type->name(),
                    credential: new StoredCredential($id, identifier: null, secret: null, label: $enrolled->label),
                );

                return null;
            }, $pending);
        } catch (Barred) {
            return 'keystone.barred';
        } catch (Superseded) {
            return 'keystone.superseded';
        }
    }
}

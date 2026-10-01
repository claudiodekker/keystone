<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * @internal
 */
class EnrollmentAttempt extends EnrollmentStep
{
    /**
     * Verify the answer to the type's enrollment ceremony and store the new credential, then sign in or keep the sign-in held for what the account still owes.
     *
     * @param  array<string, mixed>  $input
     */
    public function attempt(PendingSignIn $pending, CredentialType $type, #[\SensitiveParameter] array $input, #[\SensitiveParameter] mixed $ceremony): Demand
    {
        $proof = $this->prove($pending->account, $type, $input, $ceremony);

        if ($proof->enrolled === null) {
            $this->recordRejected($pending, $type->name(), reason: $proof->reason ?? 'keystone.not_enrolled');

            return Demand::REFUSE;
        }

        $refusal = $this->store($pending->account, $type, $proof->enrolled);

        if ($refusal !== null) {
            $this->recordRejected($pending, $type->name(), reason: $refusal);

            return Demand::REFUSE;
        }

        $this->guard->slots()->forget($type->name(), Surface::ENROLLMENT->value);

        return $this->proceed($type->name());
    }

    /**
     * Let the type verify the input against its ceremony, turning any failure into a rejection.
     *
     * @param  array<string, mixed>  $input
     */
    protected function prove(Model&KeystoneUser $account, CredentialType $type, #[\SensitiveParameter] array $input, #[\SensitiveParameter] mixed $ceremony): Proof
    {
        $credentials = new Credentials($account);

        try {
            $usable = $credentials->ofType($account->getKey(), $type->name());

            return $type->verify(Surface::ENROLLMENT, $input, $usable, $ceremony);
        } catch (Throwable $e) {
            report($e);

            return Proof::rejected('keystone.verify_failed');
        }
    }

    /**
     * Store the enrolled credential on the account and record it, or give why not once the account is locked: barred, or holding a second factor already.
     */
    protected function store(Model&KeystoneUser $account, CredentialType $type, EnrolledCredential $enrolled): ?string
    {
        $changes = new AccountChanges($this->guard, $this->recorder);

        return $changes->change($account, function (AccountChange $change) use ($type, $enrolled) {
            if ((new SignInDecision)->isBarred($change->account)) {
                return 'keystone.barred';
            }

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
        });
    }
}

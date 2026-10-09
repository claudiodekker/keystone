<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * @internal
 */
class RecoveryCodeSetup
{
    /**
     * What concludes the setup once the typed code is accepted.
     */
    protected AcceptedProof $accepted;

    /**
     * Create a new recovery code setup instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        $this->accepted = new AcceptedProof($recorder);
    }

    /**
     * Get the set of codes staged for the pending sign-in, staging a new set when none is.
     *
     * @return list<string>
     */
    public function staged(): array
    {
        $slots = $this->guard->slots();
        $staged = $slots->get(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value);

        if (is_array($staged)) {
            /** @var list<string> */
            return $staged;
        }

        $codes = (new RecoveryCodes($this->guard->userModel()))->generate();

        $slots->put(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value, $codes, capSeconds: PendingSignIn::LIFETIME_SECONDS);

        return $codes;
    }

    /**
     * Store the staged set once the typed code is one of it, then sign in or keep the sign-in held for what the account still owes.
     */
    public function confirm(PendingSignIn $pending, #[\SensitiveParameter] string $typed): Demand
    {
        $staged = $this->guard->slots()->get(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value);

        if (! is_array($staged) || ! RecoveryCodes::contains($staged, $typed)) {
            $this->recordRejected($pending, reason: 'recovery-code.mismatch');

            return Demand::REFUSE;
        }

        $refusal = $this->commit($pending, $staged);

        if ($refusal !== null) {
            $this->recordRejected($pending, reason: $refusal);

            return Demand::REFUSE;
        }

        $this->guard->slots()->forget(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value);

        return $this->accepted->conclude(new EnrollmentEntry($this->guard), $pending->account, Flow::ENROLLMENT, CredentialTypes::RECOVERY_CODE) ?? Demand::REFUSE;
    }

    /**
     * Store the set as the account's recovery codes, or give why not once the account is locked: barred, off the pending sign-in's epoch, or holding codes already.
     *
     * @param  array<array-key, mixed>  $staged
     */
    protected function commit(PendingSignIn $pending, #[\SensitiveParameter] array $staged): ?string
    {
        $changes = new AccountChanges($this->guard, $this->recorder);
        $codes = array_values(array_map(strval(...), $staged));

        return $changes->change($pending->account, function (AccountChange $change) use ($pending, $codes) {
            if ((new SignInDecision)->isBarred($change->account)) {
                return 'keystone.barred';
            }

            if (! $change->isOnEpoch($pending->epoch)) {
                return 'keystone.superseded';
            }

            if ((new RecoveryCodes($change->account))->hasRemaining($change->account->getKey())) {
                return 'keystone.recovery_codes_held';
            }

            $change->commitRecoveryCodes($codes, flow: Flow::ENROLLMENT);

            return null;
        });
    }

    /**
     * Record a refused recovery-code setup for the pending sign-in's account.
     */
    protected function recordRejected(PendingSignIn $pending, string $reason): void
    {
        $this->recorder->record(
            SecurityEventType::PROOF_REJECTED,
            account: $pending->account,
            flow: Flow::ENROLLMENT->value,
            credentialType: CredentialTypes::RECOVERY_CODE,
            reason: $reason,
        );
    }
}

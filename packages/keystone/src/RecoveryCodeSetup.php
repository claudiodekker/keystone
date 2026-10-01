<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class RecoveryCodeSetup extends EnrollmentStep
{
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

        if (! is_array($staged) || ! $this->matches($staged, $typed)) {
            $this->recordRejected($pending, CredentialTypes::RECOVERY_CODE, reason: 'recovery-code.mismatch');

            return Demand::REFUSE;
        }

        $refusal = $this->commit($pending->account, $staged);

        if ($refusal !== null) {
            $this->recordRejected($pending, CredentialTypes::RECOVERY_CODE, reason: $refusal);

            return Demand::REFUSE;
        }

        $this->guard->slots()->forget(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value);

        return $this->proceed(CredentialTypes::RECOVERY_CODE);
    }

    /**
     * Determine if the typed code is one of the staged set, compared as normalized.
     *
     * @param  array<array-key, mixed>  $staged
     */
    protected function matches(#[\SensitiveParameter] array $staged, #[\SensitiveParameter] string $typed): bool
    {
        $normalized = RecoveryCodes::normalize($typed);
        $matched = false;

        foreach ($staged as $code) {
            $matched = hash_equals(RecoveryCodes::normalize((string) $code), $normalized) || $matched;
        }

        return $matched;
    }

    /**
     * Store the set as the account's recovery codes, or give why not once the account is locked: barred, or holding codes already.
     *
     * @param  array<array-key, mixed>  $staged
     */
    protected function commit(Model&KeystoneUser $account, #[\SensitiveParameter] array $staged): ?string
    {
        $changes = new AccountChanges($this->guard, $this->recorder);
        $codes = array_values(array_map(strval(...), $staged));

        return $changes->change($account, function (AccountChange $change) use ($codes) {
            if ((new SignInDecision)->isBarred($change->account)) {
                return 'keystone.barred';
            }

            if ((new RecoveryCodes($change->account))->remaining($change->account->getKey()) > 0) {
                return 'keystone.recovery_codes_held';
            }

            $change->commitRecoveryCodes($codes, flow: Flow::ENROLLMENT);

            return null;
        });
    }
}

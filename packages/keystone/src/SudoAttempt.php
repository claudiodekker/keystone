<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class SudoAttempt extends CredentialAttempt
{
    /**
     * Prove the answer to the step the sudo-in-progress is at, then grant sudo or move on to the challenge a sign-in would demand, or refuse inside the timing floor.
     *
     * It takes the sudo-in-progress, so it can't run for a session nothing was demanded of.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     * @throws LastRecoveryCode
     */
    public function attempt(SudoInProgress $progress, CredentialType $type, #[\SensitiveParameter] array $input): SudoResult
    {
        return $this->timebox->call(function () use ($progress, $type, $input) {
            /** @var (Model&KeystoneUser)|null $account */
            $account = $this->guard->user();

            if ($account === null || ! $this->offers($account, $progress, $type)) {
                return SudoResult::REFUSED;
            }

            $flow = Flow::of($this->guard, $progress->surface());
            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');
            $subnet = $this->guard->subnet();

            if ($subnet === null) {
                $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.unbindable_subnet');

                return SudoResult::REFUSED;
            }

            if ($type instanceof RecoveryCodeType) {
                return $this->spendRecoveryCode($account, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken, $subnet);
            }

            [$proof, $credential] = $this->prove($progress->surface(), $type, $account, $input, $taken);

            if (! $this->owns($account, $type, $proof, $credential)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: $proof->reason ?? 'keystone.foreign_credential');

                return SudoResult::REFUSED;
            }

            $demand = $this->owed($account, $progress, $type);

            if ($demand === Demand::REFUSE || $demand === Demand::ENROLLMENT) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.barred');

                return SudoResult::REFUSED;
            }

            $owed = $demand === Demand::CHALLENGE;

            $entered = $this->finish(
                account: $account,
                flow: $flow,
                type: $type,
                proof: $proof,
                credential: $credential,
                taken: $taken,
                enter: fn () => $this->enter($owed, $type, $subnet),
                recorded: $owed ? null : SecurityEventType::SUDO_GRANTED,
            );

            return match (true) {
                ! $entered => SudoResult::REFUSED,
                $owed => SudoResult::CHALLENGE_OWED,
                default => SudoResult::GRANTED,
            };
        }, self::TIMING_FLOOR_MICROSECONDS);
    }

    /**
     * Determine if the step the sudo-in-progress is at offers the type to the account.
     */
    protected function offers(Model&KeystoneUser $account, SudoInProgress $progress, CredentialType $type): bool
    {
        $offer = (new SignInDecision)->replayOffer($account, $progress->firstFactor);

        return in_array($type->name(), array_map(fn (CredentialType $offered) => $offered->name(), $offer), true);
    }

    /**
     * Decide what passing the step with the type leads to: at the first step what a sign-in by that type demands, at the challenge the grant unless the account is barred.
     */
    protected function owed(Model&KeystoneUser $account, SudoInProgress $progress, CredentialType $type): Demand
    {
        $decision = new SignInDecision;

        if ($progress->firstFactor === null) {
            return $decision->demand($account, $type);
        }

        return $decision->isBarred($account) ? Demand::REFUSE : Demand::SIGN_IN;
    }

    /**
     * Spend the recovery code the typed one matches and grant sudo, refusing a barred account and always keeping the last code.
     *
     * @throws LastRecoveryCode
     */
    protected function spendRecoveryCode(Model&KeystoneUser $account, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken, Subnet $subnet): SudoResult
    {
        try {
            $spent = $this->spendCode($account, $flow, $typed, keepLast: true);
        } catch (LastRecoveryCode $e) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.last_recovery_code');

            throw $e;
        }

        if ($spent === null) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.barred');

            return SudoResult::REFUSED;
        }

        if (! $spent) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'recovery-code.mismatch');

            return SudoResult::REFUSED;
        }

        $entered = $this->complete(
            account: $account,
            flow: $flow,
            type: $type,
            credential: null,
            taken: $taken,
            enter: fn () => $this->enter(false, $type, $subnet),
            recorded: SecurityEventType::SUDO_GRANTED,
        );

        return $entered ? SudoResult::GRANTED : SudoResult::REFUSED;
    }

    /**
     * Move the sudo-in-progress past the step: on to the challenge it still owes, or into a grant bound to the subnet. No device is judged, so it returns null.
     */
    protected function enter(bool $owed, CredentialType $type, Subnet $subnet): ?bool
    {
        if ($owed) {
            $this->guard->passSudoFirstFactor($type->name());

            return null;
        }

        $this->guard->grantSudo($subnet);

        return null;
    }

    /**
     * Write what the proof changed and stamp the credential's last use in one locked change, refusing a proof another one overtook.
     *
     * It runs before the grant, so a lock failure or a lost race ends with no grant.
     */
    protected function advance(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential): bool
    {
        $changes = new AccountChanges($this->guard);

        return $changes->change($account, function (AccountChange $change) use ($credential, $type, $proof) {
            if ($proof->advancedSecret !== null && ! $change->advance($credential, type: $type->name(), secret: (string) $proof->advancedSecret)) {
                return false;
            }

            $change->stampLastUse($credential, type: $type->name());

            return true;
        });
    }

    /**
     * Get the event a refused answer records.
     */
    protected function rejectionType(): SecurityEventType
    {
        return SecurityEventType::SUDO_FAILED;
    }
}

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\LastRecoveryCode;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
class SudoAttempt extends CredentialAttempt
{
    /**
     * Whether the step being finished grants sudo, so its credential's last use is stamped.
     */
    protected bool $granting = false;

    /**
     * Prove the answer to the step the sudo-in-progress is at, then grant sudo or move on to the challenge a sign-in would demand, or refuse inside the timing floor.
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

            if ($account === null || (new SignInDecision)->replayOffered($account, $progress->firstFactor, $type->name()) === null) {
                return SudoResult::REFUSED;
            }

            try {
                $flow = Flow::of($this->guard, $progress->surface());
            } catch (LogicException) {
                return SudoResult::REFUSED;
            }

            $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');
            $subnet = $this->guard->subnet();

            if ($subnet === null) {
                $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.unbindable_subnet');

                return SudoResult::REFUSED;
            }

            if ($type instanceof RecoveryCodeType) {
                return $this->spendCodeForSudo($account, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken, $subnet);
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

            if ($this->ranOut($taken)) {
                return SudoResult::REFUSED;
            }

            $owed = $demand === Demand::CHALLENGE;
            $this->granting = ! $owed;

            $entered = $this->finish(
                account: $account,
                flow: $flow,
                type: $type,
                proof: $proof,
                credential: $credential,
                taken: $taken,
                enter: $owed ? fn () => $this->passFirstStep($progress, $type) : fn () => $this->grant($subnet),
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
     * Determine if the sudo-in-progress ran out while the answer was checked, giving the attempt back when it did.
     */
    protected function ranOut(TakenAttempt $taken): bool
    {
        if ($this->guard->sudoInProgress() !== null) {
            return false;
        }

        $this->limiter->giveBack($taken);

        return true;
    }

    /**
     * Spend the recovery code the typed one matches and grant sudo, always keeping the last code.
     *
     * @throws LastRecoveryCode
     */
    protected function spendCodeForSudo(Model&KeystoneUser $account, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken, Subnet $subnet): SudoResult
    {
        if ($this->ranOut($taken)) {
            return SudoResult::REFUSED;
        }

        $entered = $this->spendRecoveryCode(
            account: $account,
            flow: $flow,
            type: $type,
            typed: $typed,
            taken: $taken,
            enter: fn () => $this->grant($subnet),
            recorded: SecurityEventType::SUDO_GRANTED,
            keepLast: true,
        );

        return $entered ? SudoResult::GRANTED : SudoResult::REFUSED;
    }

    /**
     * Move the sudo-in-progress past its first step, on to the challenge it still owes.
     */
    protected function passFirstStep(SudoInProgress $progress, CredentialType $type): ?bool
    {
        $this->guard->passSudoFirstFactor($progress, $type->name());

        return null;
    }

    /**
     * Grant the sudo-in-progress its sudo, bound to the subnet.
     */
    protected function grant(Subnet $subnet): ?bool
    {
        $this->guard->grantSudo($subnet);

        return null;
    }

    /**
     * Write what the proof changed and, on the step that grants, stamp the credential's last use in the same locked change, refusing a proof another one overtook.
     */
    protected function advance(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential): bool
    {
        $changes = new AccountChanges($this->guard);

        return $changes->change($account, function (AccountChange $change) use ($credential, $type, $proof) {
            if ($proof->advancedSecret !== null && ! $change->advance($credential, type: $type->name(), secret: (string) $proof->advancedSecret)) {
                return false;
            }

            if ($this->granting) {
                $change->stampLastUse($credential, type: $type->name());
            }

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

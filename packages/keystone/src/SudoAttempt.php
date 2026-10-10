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
            $subnet = $this->guard->context()->subnet();

            if ($subnet === null) {
                $this->recordRejected($account, $flow, $type, credential: null, reason: 'keystone.unbindable_subnet');

                return SudoResult::REFUSED;
            }

            if ($type instanceof RecoveryCodeType) {
                return $this->spendCodeForSudo($account, $flow, $type, (string) $input[RecoveryCodeType::FIELD], $taken, $subnet, $progress);
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

            $leadsTo = $demand === Demand::CHALLENGE ? SudoResult::CHALLENGE_OWED : SudoResult::GRANTED;

            if (! $this->write($account, $type, $proof, $credential, $leadsTo)) {
                $this->recordRejected($account, $flow, $type, $credential, reason: 'keystone.superseded');

                return SudoResult::REFUSED;
            }

            $pass = $leadsTo === SudoResult::GRANTED
                ? new SudoGrantPass($this->guard, $subnet)
                : new SudoFirstStepPass($this->guard, $progress, $type->name());

            $result = $this->conclude($pass, $account, $flow, $type, $credential, $taken);

            if ($result === null) {
                return SudoResult::REFUSED;
            }

            $this->storeUpdatedSecret($account, $type, $proof, $credential);

            return $result;
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
    protected function spendCodeForSudo(Model&KeystoneUser $account, Flow $flow, RecoveryCodeType $type, #[\SensitiveParameter] string $typed, TakenAttempt $taken, Subnet $subnet, SudoInProgress $progress): SudoResult
    {
        if ($this->ranOut($taken)) {
            return SudoResult::REFUSED;
        }

        if (! $this->spendRecoveryCode($account, $flow, $type, $typed, keepLast: true, writer: $progress)) {
            return SudoResult::REFUSED;
        }

        return $this->conclude(new SudoGrantPass($this->guard, $subnet), $account, $flow, $type, credential: null, taken: $taken) ?? SudoResult::REFUSED;
    }

    /**
     * Write what the proof changed and, on a step that leads to the grant, stamp the credential's last use in the same locked change, refusing a proof another one overtook.
     */
    protected function write(Model&KeystoneUser $account, CredentialType $type, Proof $proof, StoredCredential $credential, SudoResult $leadsTo): bool
    {
        return $leadsTo === SudoResult::GRANTED
            ? $this->markUsed($account, $type, $proof, $credential)
            : $this->advance($account, $type, $proof, $credential);
    }
}

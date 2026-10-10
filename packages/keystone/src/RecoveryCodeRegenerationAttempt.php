<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Exceptions\SudoRequired;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;

/**
 * @internal
 */
class RecoveryCodeRegenerationAttempt extends CredentialAttempt
{
    /**
     * Store the staged set as the signed-in account's recovery codes once the typed code is one of it, refusing a wrong one inside the timing floor.
     *
     * @throws Throttled
     */
    public function attempt(StagedRecoveryCodes $staged, #[\SensitiveParameter] string $typed): RecoveryCodeRegenerationResult
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = $this->guard->user();

        if ($account === null) {
            return RecoveryCodeRegenerationResult::SUDO_ENDED;
        }

        return $this->timebox->call(
            fn () => $this->answer($account, $staged, $typed),
            self::TIMING_FLOOR_MICROSECONDS,
        );
    }

    /**
     * Check the typed code and store the set, counting a wrong code as a failed attempt in the settings flow.
     *
     * @throws Throttled
     */
    protected function answer(Model&KeystoneUser $account, StagedRecoveryCodes $staged, #[\SensitiveParameter] string $typed): RecoveryCodeRegenerationResult
    {
        try {
            $flow = Flow::of($this->guard, Surface::ENROLLMENT);
        } catch (LogicException) {
            return RecoveryCodeRegenerationResult::SUDO_ENDED;
        }

        $type = new RecoveryCodeType;
        $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');

        if (! RecoveryCodes::contains($staged->codes, $typed)) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: 'recovery-code.mismatch');

            return RecoveryCodeRegenerationResult::REFUSED;
        }

        $outcome = $this->storeOrCloseOnFailure($account, $staged, $typed);

        if (is_string($outcome)) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: $outcome);

            return RecoveryCodeRegenerationResult::REFUSED;
        }

        $this->limiter->giveBack($taken);

        if ($outcome === RecoveryCodeRegenerationResult::SUDO_ENDED) {
            return $outcome;
        }

        (new RecoveryCodeRegeneration($this->guard))->close();

        if ($outcome === RecoveryCodeRegenerationResult::REGENERATED) {
            $this->timebox->returnEarly();
        }

        return $outcome;
    }

    /**
     * Store the staged set, discarding it when the change throws.
     *
     * @return RecoveryCodeRegenerationResult|string the result, or the reason of a refusal
     */
    protected function storeOrCloseOnFailure(Model&KeystoneUser $account, StagedRecoveryCodes $staged, #[\SensitiveParameter] string $typed): RecoveryCodeRegenerationResult|string
    {
        try {
            return $this->store($account, $staged, $typed);
        } catch (Throwable $e) {
            (new RecoveryCodeRegeneration($this->guard))->close();

            throw $e;
        }
    }

    /**
     * Store the staged set once the account is locked, or give why not: barred, sudo ended, or staged on an epoch the account has left.
     *
     * @return RecoveryCodeRegenerationResult|string the result, or the reason of a refusal
     */
    protected function store(Model&KeystoneUser $account, StagedRecoveryCodes $staged, #[\SensitiveParameter] string $typed): RecoveryCodeRegenerationResult|string
    {
        $grant = (new SudoGate($this->guard))->liveGrant();

        if ($grant === null) {
            return RecoveryCodeRegenerationResult::SUDO_ENDED;
        }

        try {
            return (new AccountChanges($this->guard, $this->recorder))->change($account, function (AccountChange $change) use ($staged, $typed) {
                if ((new RecoveryCodes($change->account))->find($change->account->getKey(), $typed) !== null) {
                    return RecoveryCodeRegenerationResult::REGENERATED;
                }

                if (! $change->isOnEpoch($staged->epoch)) {
                    return RecoveryCodeRegenerationResult::EXPIRED;
                }

                $change->commitRecoveryCodes($staged->codes, flow: Flow::SETTINGS);

                return RecoveryCodeRegenerationResult::REGENERATED;
            }, $grant);
        } catch (Barred) {
            return 'keystone.barred';
        } catch (SudoRequired) {
            return RecoveryCodeRegenerationResult::SUDO_ENDED;
        }
    }
}

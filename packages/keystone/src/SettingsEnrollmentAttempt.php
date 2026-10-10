<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use ClaudioDekker\Keystone\Exceptions\SudoRequired;
use ClaudioDekker\Keystone\Exceptions\Superseded;
use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
class SettingsEnrollmentAttempt extends CredentialAttempt
{
    /**
     * Verify the answer to the type's running enrollment ceremony and store the new credential on the signed-in account, refusing a wrong answer inside the timing floor.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     */
    public function attempt(CredentialType $type, #[\SensitiveParameter] array $input, RunningCeremony $running): SettingsEnrollmentResult
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = $this->guard->user();

        if ($account === null) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }

        return $this->timebox->call(
            fn () => $this->answer($account, $type, $input, $running),
            self::TIMING_FLOOR_MICROSECONDS,
        );
    }

    /**
     * Prove the answer against the running ceremony and store what it enrolled, counting a refused answer as a failed attempt in the session's flow.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     */
    protected function answer(Model&KeystoneUser $account, CredentialType $type, #[\SensitiveParameter] array $input, RunningCeremony $running): SettingsEnrollmentResult
    {
        try {
            $flow = Flow::of($this->guard, Surface::ENROLLMENT);
        } catch (LogicException) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }

        $taken = $this->limiter->takeFailedAttempt($flow, $type, $account, identifier: '');
        [$proof, , $provedAgainst] = $this->prove(Surface::ENROLLMENT, $type, $account, $input, $taken, ceremony: $running->ceremony);

        if ($proof->enrolled === null) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: $proof->reason ?? 'keystone.not_enrolled');

            return SettingsEnrollmentResult::REFUSED;
        }

        $outcome = $this->store($account, $type, $proof->enrolled, $flow, $provedAgainst);

        if (is_string($outcome)) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: $outcome);

            return SettingsEnrollmentResult::REFUSED;
        }

        $this->limiter->giveBack($taken);

        if ($outcome === SettingsEnrollmentResult::SUDO_ENDED) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }

        (new EnrollmentCeremonies($this->guard))->close($type);

        $this->timebox->returnEarly();

        return $outcome;
    }

    /**
     * Store the enrolled credential and record it once the account is locked, or give why it is refused there.
     *
     * Nothing is stored for a session whose sudo ended while the answer was checked.
     *
     * @param  list<StoredCredential>  $provedAgainst
     * @return SettingsEnrollmentResult|string the result, or the reason of a refusal
     */
    protected function store(Model&KeystoneUser $account, CredentialType $type, EnrolledCredential $enrolled, Flow $flow, array $provedAgainst): SettingsEnrollmentResult|string
    {
        $grant = (new SudoGate($this->guard))->liveGrant();

        if ($grant === null) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }

        try {
            return (new AccountChanges($this->guard, $this->recorder))->change($account, function (AccountChange $change) use ($type, $enrolled, $flow, $provedAgainst) {
                if (app(CredentialTypes::class)->find($type->name(), Surface::ENROLLMENT) === null) {
                    return 'keystone.unoffered';
                }

                return $change->enroll($type, $enrolled, $flow, $provedAgainst);
            }, $grant);
        } catch (Barred) {
            return 'keystone.barred';
        } catch (Superseded) {
            return 'keystone.superseded';
        } catch (SudoRequired) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }
    }
}

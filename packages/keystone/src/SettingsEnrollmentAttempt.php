<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
class SettingsEnrollmentAttempt extends CredentialAttempt
{
    /**
     * Verify the answer to the named type's enrollment ceremony and store the new credential on the signed-in account, refusing a wrong answer inside the timing floor.
     *
     * The type is found by name on the enrollment surface and its ceremony read from the session, so a caller passes neither the type nor the ceremony.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throttled
     */
    public function attempt(string $type, #[\SensitiveParameter] array $input): SettingsEnrollmentResult
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = $this->guard->user();
        $credentialType = app(CredentialTypes::class)->find($type, Surface::ENROLLMENT);

        if ($account === null) {
            return SettingsEnrollmentResult::SUDO_ENDED;
        }

        if ($credentialType === null) {
            return SettingsEnrollmentResult::REFUSED;
        }

        $running = (new EnrollmentCeremonies($this->guard))->running($credentialType);

        if ($running === null) {
            return SettingsEnrollmentResult::EXPIRED;
        }

        return $this->timebox->call(
            fn () => $this->answer($account, $credentialType, $input, $running),
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
        [$proof] = $this->prove(Surface::ENROLLMENT, $type, $account, $input, $taken, ceremony: $running->ceremony);

        if ($proof->enrolled === null) {
            $this->recordRejected($account, $flow, $type, credential: null, reason: $proof->reason ?? 'keystone.not_enrolled');

            return SettingsEnrollmentResult::REFUSED;
        }

        $outcome = $this->store($account, $type, $proof->enrolled, $flow);

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

        return SettingsEnrollmentResult::ENROLLED;
    }

    /**
     * Store the enrolled credential and record it once the account is locked, or give why it is refused there: barred, or a type no longer listed on enrollment.
     *
     * Nothing is stored for a session whose sudo ended while the answer was checked.
     *
     * @return SettingsEnrollmentResult|string the result, or the reason of a refusal
     */
    protected function store(Model&KeystoneUser $account, CredentialType $type, EnrolledCredential $enrolled, Flow $flow): SettingsEnrollmentResult|string
    {
        return (new AccountChanges($this->guard, $this->recorder))->change($account, function (AccountChange $change) use ($type, $enrolled, $flow) {
            if ((new SignInDecision)->isBarred($change->account)) {
                return 'keystone.barred';
            }

            if (app(CredentialTypes::class)->find($type->name(), Surface::ENROLLMENT) === null) {
                return 'keystone.unoffered';
            }

            if ((new SudoGate($this->guard))->liveGrant() === null) {
                return SettingsEnrollmentResult::SUDO_ENDED;
            }

            $change->enroll($type, $enrolled, $flow);

            return SettingsEnrollmentResult::ENROLLED;
        });
    }
}

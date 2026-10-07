<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\StoredCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class AcceptedProof
{
    /**
     * Create a new accepted proof instance.
     *
     * @param  RateLimiter|null  $limiter  the limiter the step took its failed attempt from, or null for a step that takes none
     */
    public function __construct(
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
        protected ?RateLimiter $limiter = null,
    ) {
        //
    }

    /**
     * Conclude the step an accepted proof passed: give its taken attempt back and record what the pass earned, or refuse an account barred from it.
     *
     * @template TOutcome of Demand|SudoResult
     *
     * @param  Passed<TOutcome>|null  $passed  what the pass earned, or null for an account barred from it
     * @return TOutcome|null
     */
    public function conclude(
        ?Passed $passed,
        Model&KeystoneUser $account,
        Flow $flow,
        string $credentialType,
        ?StoredCredential $credential = null,
        ?TakenAttempt $taken = null,
    ): Demand|SudoResult|null {
        if ($passed === null) {
            $this->recorder->record(
                $flow->rejectionType(),
                account: $account,
                flow: $flow->value,
                credentialType: $credentialType,
                credential: $credential,
                reason: 'keystone.barred',
            );

            return null;
        }

        if ($taken !== null) {
            $this->limiter?->giveBack($taken);
        }

        if ($passed->event !== null) {
            $this->recorder->record(
                $passed->event,
                account: $account,
                flow: $flow->value,
                credentialType: $credentialType,
                credential: $credential,
                reason: $passed->reason,
                knownDevice: $passed->knownDevice,
            );
        }

        return $passed->outcome;
    }
}

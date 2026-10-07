<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 *
 * @implements Pass<SudoResult>
 */
class SudoFirstStepPass implements Pass
{
    /**
     * Create a new sudo first step pass instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected SudoInProgress $progress,
        protected string $firstFactor,
    ) {
        //
    }

    /**
     * Move the sudo-in-progress past its first step, on to the challenge it still owes.
     *
     * @return Passed<SudoResult>
     */
    public function make(): Passed
    {
        $this->guard->passSudoFirstFactor($this->progress, $this->firstFactor);

        return new Passed(SudoResult::CHALLENGE_OWED);
    }
}

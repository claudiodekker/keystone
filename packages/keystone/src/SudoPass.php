<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
class SudoPass
{
    /**
     * Create a new sudo pass instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
    ) {
        //
    }

    /**
     * Grant the sudo-in-progress its sudo, bound to the subnet.
     *
     * @return Passed<SudoResult>
     */
    public function grant(Subnet $subnet): Passed
    {
        $this->guard->grantSudo($subnet);

        return new Passed(SudoResult::GRANTED, SecurityEventType::SUDO_GRANTED);
    }

    /**
     * Move the sudo-in-progress past its first step, on to the challenge it still owes.
     *
     * @return Passed<SudoResult>
     */
    public function passFirstStep(SudoInProgress $progress, string $firstFactor): Passed
    {
        $this->guard->passSudoFirstFactor($progress, $firstFactor);

        return new Passed(SudoResult::CHALLENGE_OWED);
    }
}

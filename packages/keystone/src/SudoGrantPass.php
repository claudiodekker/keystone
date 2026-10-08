<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 *
 * @implements Pass<SudoResult>
 */
class SudoGrantPass implements Pass
{
    /**
     * Create a new sudo grant pass instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
        protected Subnet $subnet,
    ) {
        //
    }

    /**
     * Grant the sudo-in-progress its sudo, bound to the subnet.
     *
     * @return Passed<SudoResult>
     */
    public function make(): Passed
    {
        $this->guard->grantSudo($this->subnet);

        return new Passed(SudoResult::GRANTED, SecurityEventType::SUDO_GRANTED);
    }
}

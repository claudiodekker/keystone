<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;

/**
 * @internal
 */
class SecondFactorEnrollmentEntry extends EnrollmentEntry
{
    /**
     * Note that enrolling a second factor passed it, then enter as any enrollment does.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    public function make(): Passed
    {
        $this->guard->passSecondFactor();

        return parent::make();
    }
}

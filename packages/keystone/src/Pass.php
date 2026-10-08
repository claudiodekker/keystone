<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;

/**
 * @internal
 *
 * @template-covariant TOutcome of Demand|SudoResult
 */
interface Pass
{
    /**
     * Do what passing the step earns the session.
     *
     * @return Passed<TOutcome>
     *
     * @throws Barred
     */
    public function make(): Passed;
}

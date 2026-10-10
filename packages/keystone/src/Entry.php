<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Barred;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * @implements Pass<Demand>
 */
abstract class Entry implements Pass
{
    /**
     * Create a new entry instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
    ) {
        //
    }

    /**
     * Sign the account in, noting whether its browser was a known device of it, and whether the sign-in alerts about a new device as the origin decides.
     *
     * @return Passed<Demand>
     *
     * @throws Barred
     */
    protected function signIn(Model&KeystoneUser $account, RememberMe $rememberMe, PendingOrigin $origin = PendingOrigin::LOGIN): Passed
    {
        return new Passed(Demand::SIGN_IN, SecurityEventType::SIGNED_IN, knownDevice: $this->guard->signIn($account, $rememberMe), alert: $origin->alertsNewDevice());
    }
}

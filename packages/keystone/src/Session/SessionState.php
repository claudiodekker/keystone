<?php

namespace ClaudioDekker\Keystone\Session;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use Illuminate\Contracts\Session\Session;

/**
 * Own Keystone's side of the session: who is signed in.
 *
 * Every change of auth level goes through here. Controllers never touch the session or the guard.
 *
 * @internal
 */
class SessionState
{
    /**
     * Create the session state over the request's session.
     */
    public function __construct(protected Session $session)
    {
        //
    }

    /**
     * Determine if the session is signed in.
     */
    public function isSignedIn(): bool
    {
        return Keystone::guard()->check();
    }

    /**
     * Complete a sign-in for the account, and get the path to send the user to.
     */
    public function signIn(KeystoneUser $account): string
    {
        $guard = Keystone::guard();

        $this->session->put($guard->getName(), $account->getAuthIdentifier());
        $guard->setUser($account);

        return '/';
    }
}

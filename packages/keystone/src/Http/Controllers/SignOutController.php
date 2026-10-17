<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * End the session, ending in one response hook.
 *
 * @api
 */
abstract class SignOutController
{
    /**
     * Sign the user out, a guest included, so a repeated sign-out ends the same way.
     */
    public function destroy(Request $request, SessionState $session): Response|Responsable
    {
        $session->signOut();

        return $this->sendSignedOut($request);
    }

    /**
     * Send the signed-out user to sign in again.
     */
    abstract protected function sendSignedOut(Request $request): Response|Responsable;
}

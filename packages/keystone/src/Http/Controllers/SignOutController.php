<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
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
    public function destroy(Request $request, SessionState $session, Recorder $recorder): Response|Responsable
    {
        $user = $session->signOut();

        if ($user !== null) {
            $recorder->record(SecurityEventType::SIGNED_OUT, account: $user);
        }

        return $this->sendSignedOut($request);
    }

    /**
     * Send the signed-out user to sign in again.
     */
    abstract protected function sendSignedOut(Request $request): Response|Responsable;
}

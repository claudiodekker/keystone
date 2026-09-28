<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class SignOutController
{
    /**
     * Sign out, ending the session.
     */
    public function __invoke(Request $request): Response|Responsable
    {
        $guard = Keystone::guard();

        if (! $guard->check()) {
            return redirect()->route('login');
        }

        $guard->signOut();

        Status::SIGNED_OUT->flash($request);

        return $this->sendSignedOut($request);
    }

    /**
     * Respond to a completed sign-out.
     */
    abstract protected function sendSignedOut(Request $request): Response|Responsable;
}

<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Http\Pages\SignInPage;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\SignIn\SignIn;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Show the sign-in page and take a proof of a credential type, ending every outcome in one response hook.
 *
 * @api
 */
abstract class SignInController
{
    /**
     * Show the sign-in page to a guest.
     */
    public function show(Request $request, SessionState $session): Response|Responsable
    {
        if ($session->isSignedIn()) {
            return $this->sendAlreadySignedIn($request);
        }

        return $this->sendSignInPage($request, new SignInPage);
    }

    /**
     * Take a guest's proof of the credential type the route names.
     */
    public function store(Request $request, string $type, SignIn $signIn): Response|Responsable
    {
        $redirectTo = $signIn->attempt($type, $request->all());

        return $this->sendSignedIn($request, $redirectTo);
    }

    /**
     * Render the sign-in page from its page value.
     */
    abstract protected function sendSignInPage(Request $request, SignInPage $page): Response|Responsable;

    /**
     * Refuse a signed-in user at a guest step.
     */
    abstract protected function sendAlreadySignedIn(Request $request): Response|Responsable;

    /**
     * Send the user on after a completed sign-in.
     */
    abstract protected function sendSignedIn(Request $request, string $redirectTo): Response|Responsable;
}

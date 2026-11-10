<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\ChallengeController as BaseChallengeController;
use ClaudioDekker\Keystone\Http\Pages\ChallengePage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallengeController extends BaseChallengeController
{
    /**
     * Render the challenge page with the page value's fields as view data.
     */
    protected function sendChallengePage(Request $request, ChallengePage $page): Response
    {
        return response()->view('fixtures::challenge', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Send a session without a pending sign-in to sign in.
     */
    protected function sendNoPendingSignIn(Request $request): Response
    {
        return to_route('login');
    }

    /**
     * Return to the challenge with the message on the type's field, flashing back all input as a careless app would.
     */
    protected function sendChallengeRefused(Request $request, string $field, string $message): Response
    {
        return to_route('login.challenge')
            ->withErrors([$field => $message])
            ->withInput();
    }

    /**
     * Return to the challenge with the message on the code's field, flashing back all input as a careless app would.
     */
    protected function sendLastRecoveryCodeKept(Request $request, string $field, string $message): Response
    {
        return to_route('login.challenge')
            ->withErrors([$field => $message])
            ->withInput();
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): Response
    {
        return redirect($redirectTo);
    }

    /**
     * Send the user to sign in again.
     */
    protected function sendChallengeCancelled(Request $request): Response
    {
        return to_route('login');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\ChallengeController as KeystoneChallengeController;
use ClaudioDekker\Keystone\Http\Pages\ChallengePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Answer every outcome of the second-factor challenge. Keystone runs the flow; this decides what users see.
 */
class ChallengeController extends KeystoneChallengeController
{
    /**
     * Render the challenge page, with the browser history encrypted, and the page value's fields as props.
     */
    protected function sendChallengePage(Request $request, ChallengePage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Challenge', [
            'preselected' => $page->preselected,
            'types' => $page->types,
        ]);
    }

    /**
     * Send a session without a pending sign-in to sign in.
     */
    protected function sendNoPendingSignIn(Request $request): RedirectResponse
    {
        return to_route('login');
    }

    /**
     * Return to the challenge with the message on the type's field, flashing back no input.
     */
    protected function sendChallengeRefused(Request $request, string $field, string $message): RedirectResponse
    {
        return to_route('login.challenge')->withErrors([$field => $message]);
    }

    /**
     * Send the user on to where the sign-in lands.
     */
    protected function sendSignedIn(Request $request, string $redirectTo): RedirectResponse
    {
        return redirect($redirectTo);
    }

    /**
     * Send the user to sign in again.
     */
    protected function sendChallengeCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}

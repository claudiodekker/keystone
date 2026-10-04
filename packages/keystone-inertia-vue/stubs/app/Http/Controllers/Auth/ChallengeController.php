<?php

namespace App\Http\Controllers\Auth;

use ClaudioDekker\Keystone\Http\Controllers\ChallengeController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\ChallengePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChallengeController extends Controller
{
    /**
     * Respond with the challenge page, kept encrypted in the browser's history.
     */
    protected function sendChallengePage(Request $request, ChallengePage $page): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('auth/Challenge', [
            'types' => $page->types,
            'preselect' => $page->preselect,
        ]);
    }

    /**
     * Respond to a refused answer, with the message for the credential type's field.
     */
    protected function sendChallengeRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.challenge')->withErrors([$type => $message]);
    }

    /**
     * Respond to a passed challenge whose account still owes an enrollment, sending the user on to it.
     */
    protected function sendEnrollmentOwedAfterChallenge(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    /**
     * Respond to a passed challenge, sending the signed-in user on to the intended URL.
     */
    protected function sendChallengePassed(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    /**
     * Respond to a cancelled challenge on the sign-in page.
     */
    protected function sendChallengeCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}

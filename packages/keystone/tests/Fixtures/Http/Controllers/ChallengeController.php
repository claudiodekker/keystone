<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\ChallengeController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\ChallengePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    protected function sendChallengePage(Request $request, ChallengePage $page): JsonResponse
    {
        return response()->json(['types' => $page->types, 'preselect' => $page->preselect]);
    }

    protected function sendSecondFactorUnavailable(Request $request): RedirectResponse
    {
        return to_route('login');
    }

    protected function sendChallengeRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('login.challenge')->withErrors([$type => $message]);
    }

    protected function sendChallengePassed(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    protected function sendChallengeCancelled(Request $request): RedirectResponse
    {
        return to_route('login');
    }
}

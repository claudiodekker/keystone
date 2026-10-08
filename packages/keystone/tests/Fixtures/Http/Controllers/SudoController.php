<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SudoController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SudoPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SudoController extends Controller
{
    protected function sendSudoPage(Request $request, SudoPage $page): JsonResponse
    {
        return response()->json(['types' => $page->types, 'preselect' => $page->preselect, 'surface' => $page->surface]);
    }

    protected function sendSudoRefused(Request $request, string $type, string $message): RedirectResponse
    {
        return to_route('sudo')->withErrors([$type => $message]);
    }

    protected function sendSudoChallengeOwed(Request $request): RedirectResponse
    {
        return to_route('sudo');
    }

    protected function sendSudoGranted(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }

    protected function sendSudoEnded(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}

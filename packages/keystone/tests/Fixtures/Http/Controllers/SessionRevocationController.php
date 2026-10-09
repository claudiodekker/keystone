<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SessionRevocationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SessionRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionRevocationController extends Controller
{
    protected function sendRevocationPage(Request $request, SessionRow $session): JsonResponse
    {
        return response()->json(['page' => 'session-revocation', 'session' => $session]);
    }

    protected function sendRevocationRefused(Request $request, string $session, string $message): RedirectResponse
    {
        return to_route('security')->withErrors(['session' => $message]);
    }

    protected function sendSessionRevoked(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    protected function sendSessionNotFound(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    protected function sendSessionsUnavailable(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}

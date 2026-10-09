<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\OtherSessionsController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OtherSessionsController extends Controller
{
    protected function sendSignOutOthersPage(Request $request): JsonResponse
    {
        return response()->json(['page' => 'sign-out-others']);
    }

    protected function sendOtherSessionsRevoked(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}

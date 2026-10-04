<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RecoveryCodesController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodesPage;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecoveryCodesController extends Controller
{
    protected function sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page): JsonResponse
    {
        return response()->json(['codes' => $page->codes]);
    }

    protected function sendRecoveryCodeRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('login.recovery-codes')->withErrors([RecoveryCodeType::FIELD => $message]);
    }

    protected function sendSecondFactorOwed(Request $request): RedirectResponse
    {
        return to_route('login.enrollment');
    }

    protected function sendRecoveryCodesSaved(Request $request, string $intendedUrl): RedirectResponse
    {
        return redirect($intendedUrl);
    }
}

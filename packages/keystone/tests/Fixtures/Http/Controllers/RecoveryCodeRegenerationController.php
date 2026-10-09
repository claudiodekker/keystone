<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RecoveryCodeRegenerationController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RecoveryCodeRegenerationPage;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecoveryCodeRegenerationController extends Controller
{
    /**
     * Send the staged set as JSON.
     */
    protected function sendRecoveryCodeRegenerationPage(Request $request, RecoveryCodeRegenerationPage $page): JsonResponse
    {
        return response()->json(['page' => 'regenerate-recovery-codes', 'codes' => $page->codes, 'replaces' => $page->replaces, 'status' => $page->status]);
    }

    /**
     * Send the user back to the step with the refusal.
     */
    protected function sendRecoveryCodeRegenerationRefused(Request $request, string $message): RedirectResponse
    {
        return to_route('security.recovery-codes.regenerate')->withErrors([RecoveryCodeType::FIELD => $message]);
    }

    /**
     * Send the user back to the step to stage a fresh set.
     */
    protected function sendRecoveryCodeRegenerationExpired(Request $request): RedirectResponse
    {
        return to_route('security.recovery-codes.regenerate');
    }

    /**
     * Send the user to the security page once the set is stored.
     */
    protected function sendRecoveryCodesRegenerated(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    /**
     * Send the user to the security page once the staged set is discarded.
     */
    protected function sendRecoveryCodeRegenerationCancelled(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}

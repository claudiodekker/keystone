<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\CredentialRemovalController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\CredentialRemovalPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CredentialRemovalController extends Controller
{
    protected function sendRemovalPage(Request $request, CredentialRemovalPage $page): JsonResponse
    {
        return response()->json(['id' => $page->id, 'type' => $page->type, 'label' => $page->label, 'listed' => $page->listed]);
    }

    protected function sendCredentialRemoved(Request $request): RedirectResponse
    {
        return to_route('security');
    }

    protected function sendCredentialNotFound(Request $request): RedirectResponse
    {
        return to_route('security');
    }
}

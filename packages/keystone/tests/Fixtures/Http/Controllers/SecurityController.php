<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SecurityController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\SecurityPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    protected function sendSecurityPage(Request $request, SecurityPage $page): JsonResponse
    {
        return response()->json([
            'types' => $page->types,
            'leftovers' => $page->leftovers,
            'recoveryCodes' => $page->recoveryCodes,
            'recoveryCodesLow' => $page->recoveryCodesLow,
            'sudoEndsAt' => $page->sudoEndsAt,
            'status' => $page->status,
            'offersSignOutOthers' => $page->offersSignOutOthers,
        ]);
    }
}

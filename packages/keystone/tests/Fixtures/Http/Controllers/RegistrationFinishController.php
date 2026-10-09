<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\RegistrationFinishController as Controller;
use ClaudioDekker\Keystone\Http\PageValues\RegistrationFinishPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationFinishController extends Controller
{
    /**
     * Send the finish page as JSON.
     */
    protected function sendRegistrationFinishPage(Request $request, RegistrationFinishPage $page): JsonResponse
    {
        return response()->json(['page' => 'register-finish', 'address' => $page->address, 'types' => $page->types, 'status' => $page->status]);
    }
}

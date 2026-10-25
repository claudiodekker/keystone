<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as BaseSignOutController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SignOutController extends BaseSignOutController
{
    /**
     * Return to the sign-in page.
     */
    protected function sendSignedOut(Request $request): Response
    {
        return to_route('login');
    }
}

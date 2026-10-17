<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\SignOutController as BaseSignOutController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer signing out the way Keystone's default assertions expect.
 */
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

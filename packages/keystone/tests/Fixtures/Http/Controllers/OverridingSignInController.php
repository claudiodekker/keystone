<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OverridingSignInController extends SignInController
{
    public function show(Request $request): Response
    {
        return response('The app\'s own sign-in page.')->withHeaders([
            'Cache-Control' => 'public, max-age=3600',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => ["default-src 'self'; script-src 'self' 'nonce-app'; FRAME-ANCESTORS *; object-src *", "img-src 'self'"],
        ]);
    }
}

<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * An app's subclass that replaced the gated action, and with it the inline gate call.
 */
class OverridingGatedProbeController extends GatedProbeController
{
    public function destroy(Request $request): Response
    {
        return response('the app\'s own change was made');
    }
}

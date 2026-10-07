<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OverridingGatedProbeController extends GatedProbeController
{
    public function destroy(Request $request): Response
    {
        return response('the app\'s own change was made');
    }
}

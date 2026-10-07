<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use ClaudioDekker\Keystone\Http\Controllers\Controller;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\SudoGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A gated Keystone controller as #45 will write one: sudo in its middleware list and on the first line of its action.
 */
class GatedProbeController extends Controller
{
    public static function middleware(): array
    {
        return [
            static::throttle(StepKind::CHANGE, 'destroy'),
            static::sudo('destroy'),
        ];
    }

    public function destroy(Request $request): Response
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        return response('the change was made');
    }
}

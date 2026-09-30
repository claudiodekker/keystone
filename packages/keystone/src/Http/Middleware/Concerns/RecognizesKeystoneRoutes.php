<?php

namespace ClaudioDekker\Keystone\Http\Middleware\Concerns;

use ClaudioDekker\Keystone\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * @internal
 */
trait RecognizesKeystoneRoutes
{
    /**
     * Determine if the request was routed to one of Keystone's controllers, or an app's subclass of one.
     */
    protected function routesToKeystone(Request $request): bool
    {
        $route = $request->route();
        $controller = $route instanceof Route ? $route->getControllerClass() : null;

        return $controller !== null && is_a($controller, Controller::class, true);
    }
}

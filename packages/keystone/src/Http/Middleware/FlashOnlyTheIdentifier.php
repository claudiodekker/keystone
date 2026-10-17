<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep only the identifier in the input a Keystone action flashes back, whatever answered: a hook, a refusal or Laravel's validation [MF-12].
 *
 * @internal
 */
class FlashOnlyTheIdentifier
{
    /**
     * Narrow the flashed input once the action's response, a rendered exception included, is built.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $session = $request->session();

        $session->put('_old_input', Arr::only($session->get('_old_input', []), ['identifier']));

        return $response;
    }
}

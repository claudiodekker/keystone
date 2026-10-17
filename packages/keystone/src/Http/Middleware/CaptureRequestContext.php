<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capture the request context once per request.
 *
 * @internal
 */
class CaptureRequestContext
{
    /**
     * Capture the context and keep it on the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::capture($request)->storeOn($request);

        return $next($request);
    }
}

<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\RequestContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class CaptureRequestContext
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Application $app,
    ) {
        //
    }

    /**
     * Capture the request's context once, for every security event it records.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = RequestContext::capture($request);

        $this->app->instance(RequestContext::class, $context);

        return $next($request);
    }
}

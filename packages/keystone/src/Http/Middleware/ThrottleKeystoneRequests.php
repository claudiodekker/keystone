<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\StepKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class ThrottleKeystoneRequests
{
    /**
     * Count the request against its step kind's limits, letting a spent limit's Throttled render as the 429.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        (new RateLimiter($request, app(RequestContext::class), Keystone::guard()))->hitRequest(StepKind::from($kind));

        return $next($request);
    }
}

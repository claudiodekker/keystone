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
     * Create a new middleware instance.
     */
    public function __construct(
        protected RequestContext $context,
    ) {
        //
    }

    /**
     * Count the request against its step kind's limits, letting a spent limit's Throttled render as the 429.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        (new RateLimiter($request, $this->context, Keystone::guard()))->hitRequest(StepKind::from($kind));

        return $next($request);
    }
}

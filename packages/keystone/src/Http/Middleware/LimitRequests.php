<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class LimitRequests
{
    /**
     * Create the middleware.
     */
    public function __construct(
        protected Limiter $limiter,
    ) {
        //
    }

    /**
     * Get the middleware for the step kind, as a controller declares it.
     */
    public static function for(StepKind $kind): string
    {
        return static::class.':'.$kind->value;
    }

    /**
     * Take the request, then run the action.
     */
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $this->limiter->takeRequest(StepKind::from($kind));

        return $next($request);
    }
}

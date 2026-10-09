<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SendNoReferrer
{
    /**
     * Mark the request so the hardening floor sends no referrer with whatever answers it, a refusal included.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(AddHardeningHeaders::NO_REFERRER, true);

        return $next($request);
    }
}

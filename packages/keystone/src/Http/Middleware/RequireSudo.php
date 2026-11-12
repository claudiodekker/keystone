<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class RequireSudo
{
    /**
     * The alias apps gate their routes with.
     */
    public const string ALIAS = 'sudo';

    /**
     * Let the request through.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}

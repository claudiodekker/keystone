<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\SudoGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class RequireSudo
{
    /**
     * Refuse the request unless its session holds sudo.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        (new SudoGate(Keystone::guard()))->enforce($request);

        return $next($request);
    }
}

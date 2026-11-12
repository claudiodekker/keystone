<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\SudoGate;
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
     * Create the middleware.
     */
    public function __construct(
        protected SudoGate $gate,
    ) {
        //
    }

    /**
     * Let the request through only once the gate does.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->gate->ensure($request);

        return $next($request);
    }
}

<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class RequireOpenRegistration
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected CredentialTypes $types,
    ) {
        //
    }

    /**
     * Send every registration step to sign in while no listed credential type serves registration, before it validates, looks up or mails anything.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->types->serving(Surface::REGISTRATION) === []) {
            Status::REGISTRATION_UNAVAILABLE->flash($request);

            return redirect()->route('login');
        }

        return $next($request);
    }
}

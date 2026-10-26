<?php

namespace ClaudioDekker\Keystone\Http;

use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SessionExpiry
{
    /**
     * Create the renderer.
     */
    public function __construct(
        protected SessionExpiredResponse $response,
    ) {
        //
    }

    /**
     * Mark the request as one whose session Keystone ended at its absolute lifetime.
     */
    public static function forResponseTo(SymfonyRequest $request): void
    {
        $request->attributes->set(static::class, true);
    }

    /**
     * Render the app's expiry response when the request's session expired during it.
     */
    public function render(AuthenticationException $exception, Request $request): ?Response
    {
        if (! $request->attributes->has(static::class)) {
            return null;
        }

        return Router::toResponse($request, $this->response->toResponse($request, $exception));
    }
}

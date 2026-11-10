<?php

namespace ClaudioDekker\Keystone\Http;

use ClaudioDekker\Keystone\Contracts\SessionDemotedResponse;
use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class EndedSession
{
    /**
     * Create the renderer.
     */
    public function __construct(
        protected SessionDemotedResponse $demoted,
        protected SessionExpiredResponse $expired,
    ) {
        //
    }

    /**
     * Mark the request as one whose session Keystone ended because its account newly owes enrollment.
     */
    public static function demoted(SymfonyRequest $request): void
    {
        $request->attributes->set(static::class, 'demoted');
    }

    /**
     * Mark the request as one whose session Keystone ended at its absolute lifetime.
     */
    public static function expired(SymfonyRequest $request): void
    {
        $request->attributes->set(static::class, 'expired');
    }

    /**
     * Render the app's response to why the request's session ended during it, when Keystone ended it.
     */
    public function render(AuthenticationException $exception, Request $request): ?Response
    {
        $response = match ($request->attributes->get(static::class)) {
            'demoted' => $this->demoted->toResponse($request, $exception),
            'expired' => $this->expired->toResponse($request, $exception),
            default => null,
        };

        if ($response === null) {
            return null;
        }

        return Router::toResponse($request, $response);
    }
}

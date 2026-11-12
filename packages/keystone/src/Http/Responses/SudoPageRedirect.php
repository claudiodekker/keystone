<?php

namespace ClaudioDekker\Keystone\Http\Responses;

use ClaudioDekker\Keystone\Contracts\SudoRequiredResponse;
use ClaudioDekker\Keystone\Exceptions\SudoRequiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SudoPageRedirect implements SudoRequiredResponse
{
    /**
     * The name of the route of the sudo page.
     */
    protected const string ROUTE = 'sudo';

    /**
     * Create the response.
     */
    public function __construct(
        protected Redirector $redirector,
    ) {
        //
    }

    /**
     * Send a browser to the sudo page, and tell a JSON client the request needs sudo.
     */
    public function toResponse(Request $request, SudoRequiredException $exception): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'reason' => 'sudo',
            ], 403);
        }

        return $this->redirector->route(static::ROUTE);
    }
}

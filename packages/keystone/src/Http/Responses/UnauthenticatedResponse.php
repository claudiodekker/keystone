<?php

namespace ClaudioDekker\Keystone\Http\Responses;

use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class UnauthenticatedResponse implements SessionExpiredResponse
{
    /**
     * Create the response.
     */
    public function __construct(
        protected Redirector $redirector,
    ) {
        //
    }

    /**
     * Answer as Laravel answers a guest, telling a JSON client the session expired.
     */
    public function toResponse(Request $request, AuthenticationException $exception): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => Status::SESSION_EXPIRED->label(),
                'reason' => 'expired',
            ], 401);
        }

        $redirectTo = $exception->redirectTo($request);

        if (! $redirectTo) {
            return new Response(status: 401);
        }

        return $this->redirector->guest($redirectTo);
    }
}

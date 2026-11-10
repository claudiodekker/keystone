<?php

namespace ClaudioDekker\Keystone\Http\Responses;

use ClaudioDekker\Keystone\Contracts\SessionDemotedResponse;
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
class UnauthenticatedResponse implements SessionDemotedResponse, SessionExpiredResponse
{
    /**
     * Create the response, telling a JSON client the status and the reason its session ended.
     */
    public function __construct(
        protected Redirector $redirector,
        protected Status $status,
        protected string $reason,
    ) {
        //
    }

    /**
     * Answer as Laravel answers a guest, telling a JSON client why its session ended.
     */
    public function toResponse(Request $request, AuthenticationException $exception): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $this->status->label(),
                'reason' => $this->reason,
            ], 401);
        }

        $redirectTo = $exception->redirectTo($request);

        if (! $redirectTo) {
            return new Response(status: 401);
        }

        return $this->redirector->guest($redirectTo);
    }
}

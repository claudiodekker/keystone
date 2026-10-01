<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
class RespondToDemotedSession
{
    /**
     * Respond to a request whose signed-in session Keystone held back at enrollment, because its account newly owes one.
     */
    public function handle(Request $request, AuthenticationException $e): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => Status::ENROLLMENT_OWED->label(),
                'reason' => 'demoted',
            ], 403);
        }

        return redirect()->route('login.enrollment');
    }
}

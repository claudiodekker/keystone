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
     * Respond to a request whose session Keystone ended because its account newly owes enrollment, keeping the page it asked for as the one its next sign-in lands on.
     */
    public function handle(Request $request, AuthenticationException $e): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => Status::ENROLLMENT_OWED->label(),
                'reason' => 'demoted',
            ], 401);
        }

        return redirect()->guest($e->redirectTo($request) ?? route('login'));
    }
}

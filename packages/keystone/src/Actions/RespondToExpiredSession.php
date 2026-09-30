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
class RespondToExpiredSession
{
    /**
     * Respond to a request whose session Keystone ended because it outlived its absolute lifetime.
     */
    public function handle(Request $request, AuthenticationException $e): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => Status::SESSION_EXPIRED->label(),
                'reason' => 'expired',
            ], 401);
        }

        return redirect()->guest($e->redirectTo($request) ?? route('login'));
    }
}

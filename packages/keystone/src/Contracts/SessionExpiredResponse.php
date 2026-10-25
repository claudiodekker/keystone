<?php

namespace ClaudioDekker\Keystone\Contracts;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
interface SessionExpiredResponse
{
    /**
     * Answer a request that needs a signed-in user, made with a session Keystone ended at its absolute lifetime.
     */
    public function toResponse(Request $request, AuthenticationException $exception): Response|Responsable;
}

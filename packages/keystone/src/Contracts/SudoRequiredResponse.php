<?php

namespace ClaudioDekker\Keystone\Contracts;

use ClaudioDekker\Keystone\Exceptions\SudoRequiredException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
interface SudoRequiredResponse
{
    /**
     * Answer a request the sudo gate refused, whose session now holds a sudo-in-progress that goes on to where the request was going.
     */
    public function toResponse(Request $request, SudoRequiredException $exception): Response|Responsable;
}

<?php

namespace ClaudioDekker\Keystone\Exceptions;

use ClaudioDekker\Keystone\Actions\RespondToSudoRequired;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Support\Responsable;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SudoRequired extends RuntimeException implements Responsable, ShouldntReport
{
    /**
     * Create a new sudo required exception instance.
     */
    public function __construct()
    {
        parent::__construct('The session holds no sudo grant.');
    }

    /**
     * Render the refusal the way the app answers a request that needs sudo.
     */
    public function toResponse($request): Response
    {
        return app(RespondToSudoRequired::class)->handle($request);
    }
}

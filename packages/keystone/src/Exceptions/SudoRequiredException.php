<?php

namespace ClaudioDekker\Keystone\Exceptions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @api
 *
 * @phpstan-consistent-constructor
 */
class SudoRequiredException extends AccessDeniedHttpException implements ShouldntReport
{
    /**
     * Create the exception for a request whose session holds no live sudo grant from the request's subnet.
     */
    public static function forRequest(): static
    {
        return new static(Status::SUDO_REQUIRED->label());
    }
}

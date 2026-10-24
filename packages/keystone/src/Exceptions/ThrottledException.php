<?php

namespace ClaudioDekker\Keystone\Exceptions;

use ClaudioDekker\Keystone\Status;
use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * @api
 *
 * @phpstan-consistent-constructor
 */
class ThrottledException extends TooManyRequestsHttpException implements ShouldntReport
{
    /**
     * Create the exception for a limit that frees up after the given seconds, at least one.
     */
    public static function retryAfter(int $seconds): static
    {
        $seconds = max(1, $seconds);

        return new static($seconds, Status::THROTTLED->label(['seconds' => $seconds]));
    }
}

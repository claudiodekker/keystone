<?php

namespace ClaudioDekker\Keystone\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class Throttled extends RuntimeException implements Responsable
{
    /**
     * Create a new throttled exception instance.
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('A rate limit is spent.');
    }

    /**
     * Render the refusal: 429, one message for real and made-up accounts, and when to try again.
     */
    public function toResponse($request): Response
    {
        $message = __('keystone::messages.throttled', ['seconds' => $this->retryAfterSeconds]);

        return response($message, Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $this->retryAfterSeconds]);
    }
}

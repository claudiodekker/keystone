<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\Throttled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class Controller
{
    /**
     * Run the action behind its request limit, refusing it once a limit is spent, even when a subclass replaced the action.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        $request = request();

        try {
            $this->limiter($request)->hitRequest($this->stepKind($method));

            return $this->{$method}(...array_values($parameters));
        } catch (Throttled $throttled) {
            return $this->refuseThrottled($request, $throttled->retryAfterSeconds);
        }
    }

    /**
     * Get the kind of step the action is, which picks its request limit.
     */
    abstract protected function stepKind(string $method): StepKind;

    /**
     * Refuse a step whose rate limit is spent, saying when to try again.
     */
    protected function refuseThrottled(Request $request, int $retryAfterSeconds): Response
    {
        $message = __('keystone::messages.throttled', ['seconds' => $retryAfterSeconds]);

        return response($message, Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $retryAfterSeconds]);
    }

    /**
     * Get core's rate limiter for the request.
     */
    protected function limiter(Request $request): RateLimiter
    {
        return new RateLimiter($request, Keystone::guard());
    }
}

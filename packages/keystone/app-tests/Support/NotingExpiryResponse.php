<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class NotingExpiryResponse implements SessionExpiredResponse
{
    /**
     * Whether the request had a signed-in user when the response ran, or null before it ran.
     */
    public ?bool $signedIn = null;

    /**
     * The types of the security events stored when the response ran.
     *
     * @var list<SecurityEventType>
     */
    public array $recorded = [];

    /**
     * Create the response over the reader of what Keystone recorded.
     */
    public function __construct(
        protected RecordedSecurityEvents $events,
    ) {
        //
    }

    /**
     * Note what Keystone had done by now, and answer with a teapot.
     */
    public function toResponse(Request $request, AuthenticationException $exception): Response
    {
        $this->signedIn = Auth::check();
        $this->recorded = $this->events->stored()->pluck('type')->all();

        return new Response('expired', 418);
    }
}

<?php

namespace ClaudioDekker\Keystone\Http\Controllers;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\RateLimiter;
use ClaudioDekker\Keystone\SecurityEventRecorder;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\StepKind;
use ClaudioDekker\Keystone\Throttled;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
abstract class SignOutController
{
    /**
     * Sign out, ending the session.
     */
    public function __invoke(Request $request): Response|Responsable
    {
        $guard = Keystone::guard();

        try {
            $this->limiter($request)->hitRequest(StepKind::CHANGE);
        } catch (Throttled $throttled) {
            return $this->refuseThrottled($request, $throttled->retryAfterSeconds);
        }

        if (! $guard->check()) {
            return $this->refuseGuest();
        }

        /** @var Model&KeystoneUser $account */
        $account = $guard->user();

        (new SecurityEventRecorder)->record(
            SecurityEventType::SIGNED_OUT,
            account: $account,
        );

        $guard->signOut();

        Status::SIGNED_OUT->flash($request);

        return $this->sendSignedOut($request);
    }

    /**
     * Respond to a completed sign-out.
     */
    abstract protected function sendSignedOut(Request $request): Response|Responsable;

    /**
     * Refuse the sign-out when its rate limit is spent, saying when to try again.
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

    /**
     * Send a guest away from a signed-in step.
     */
    protected function refuseGuest(): RedirectResponse
    {
        return redirect()->route('login');
    }
}

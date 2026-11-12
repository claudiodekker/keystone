<?php

namespace ClaudioDekker\Keystone\Http;

use ClaudioDekker\Keystone\Exceptions\SudoRequiredException;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * @internal
 */
class SudoGate
{
    /**
     * The request attribute that marks a request the gate refused.
     */
    protected const string REFUSED = self::class;

    /**
     * Create the gate.
     */
    public function __construct(
        protected Recorder $recorder,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Let the request through when its session holds a live sudo grant from the request's subnet.
     *
     * A live grant used from another subnet is revoked, recorded and alerted before the request is refused.
     *
     * @throws AuthenticationException
     * @throws SudoRequiredException
     */
    public function ensure(Request $request): void
    {
        if (! $this->session->isSignedIn()) {
            throw new AuthenticationException;
        }

        $grant = $this->session->liveSudoGrant();

        if ($grant === null) {
            $this->refuse($request);
        }

        if ($grant->subnet !== $this->session->subnet()) {
            $this->recordNetworkChange();
            $this->refuse($request);
        }
    }

    /**
     * Determine if the gate refused the request.
     */
    public static function refused(Request $request): bool
    {
        return $request->attributes->get(static::REFUSED) === true;
    }

    /**
     * Record that the session's live grant was used from another network, alerting the owner, before the refusal ends it.
     */
    protected function recordNetworkChange(): void
    {
        $this->recorder->record(SecurityEventType::SUDO_NETWORK_CHANGED, account: $this->session->account());
    }

    /**
     * Refuse the request sudo, keeping where to send the user once granted.
     *
     * @throws SudoRequiredException
     */
    protected function refuse(Request $request): never
    {
        $this->refuseSession($request);

        $request->attributes->set(static::REFUSED, true);

        throw SudoRequiredException::forRequest();
    }

    /**
     * Refuse the request's session sudo, keeping where to send the user once granted: a browser's GET goes back to its own URL, anything else to the page it came from.
     */
    protected function refuseSession(Request $request): void
    {
        if ($this->isBrowserGet($request)) {
            $this->session->refuseSudoGoingTo($this->pathOf($request->fullUrl()));

            return;
        }

        $this->session->refuseSudoReturningTo($this->pathOf($request->headers->get('referer')));
    }

    /**
     * Get the URL as a path on the app, or the root when it isn't one, such as a page of another site.
     */
    protected function pathOf(?string $url): string
    {
        if ($url === null) {
            return '/';
        }

        return $this->session->toPath($url) ?? '/';
    }

    /**
     * Determine if the request is a browser's GET, whose URL can be visited again.
     */
    protected function isBrowserGet(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->expectsJson();
    }
}

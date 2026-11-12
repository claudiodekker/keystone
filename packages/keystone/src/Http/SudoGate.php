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
     * The request attribute that holds how the gate answered the request.
     */
    protected const string ANSWER = self::class;

    /**
     * The answer of a request the gate let through.
     */
    protected const string PASSED = 'passed';

    /**
     * The answer of a request the gate refused.
     */
    protected const string REFUSED = 'refused';

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
     * Let the request through when its session holds a live sudo grant from the request's subnet, else refuse it by throwing.
     *
     * A guest is refused before any grant is read. A request the gate already let through is let through again without a second look.
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

        if ($request->attributes->get(static::ANSWER) === static::PASSED) {
            return;
        }

        $grant = $this->session->liveSudoGrant();

        if ($grant === null) {
            $this->refuse($request);
        }

        if ($grant->subnet !== $this->session->subnet()) {
            $this->revoke();
            $this->refuse($request);
        }

        $request->attributes->set(static::ANSWER, static::PASSED);
    }

    /**
     * Determine if the gate refused the request.
     */
    public static function refused(Request $request): bool
    {
        return $request->attributes->get(static::ANSWER) === static::REFUSED;
    }

    /**
     * Record the revocation of the session's grant, which its refusal ends, alerting the owner.
     */
    protected function revoke(): void
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
        $this->session->refuseSudo($this->intendedPath($request), replacesIntendedPath: $this->isBrowserGet($request));

        $request->attributes->set(static::ANSWER, static::REFUSED);

        throw SudoRequiredException::forRequest();
    }

    /**
     * Get the path to send the user to once granted: a browser's GET goes back to its own URL, anything else to the same-origin page it came from, else the root.
     */
    protected function intendedPath(Request $request): string
    {
        $url = $this->isBrowserGet($request) ? $request->fullUrl() : $request->headers->get('referer');

        if (! is_string($url)) {
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

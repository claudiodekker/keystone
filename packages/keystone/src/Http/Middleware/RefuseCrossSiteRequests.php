<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a cross-site request that changes something on a Keystone route, reading none of the app's CSRF exemptions.
 *
 * @internal
 */
class RefuseCrossSiteRequests extends PreventRequestForgery
{
    /**
     * Create the middleware.
     */
    public function __construct(
        Application $app,
        Encrypter $encrypter,
        protected Config $config,
        protected Recorder $recorder,
    ) {
        parent::__construct($app, $encrypter);
    }

    /**
     * Let the request through when it is safe, else record it and refuse it as Laravel refuses a CSRF mismatch.
     *
     * @param  Request  $request
     *
     * @throws TokenMismatchException
     */
    public function handle($request, Closure $next): Response
    {
        if ($this->isAllowed($request)) {
            return $next($request);
        }

        $this->recorder->record(
            SecurityEventType::REQUEST_REJECTED,
            account: null,
            reason: 'keystone.cross_site_request',
        );

        throw new TokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the request may run.
     */
    protected function isAllowed(Request $request): bool
    {
        return ! ApplyHardeningFloor::covers($request)
            || $this->isReading($request)
            || $this->isFromTheApp($request);
    }

    /**
     * Determine if the mutation came from the app's own pages.
     */
    protected function isFromTheApp(Request $request): bool
    {
        return $request->headers->get('Sec-Fetch-Site') === 'same-origin'
            || $this->tokensMatch($request)
            || $this->isTrustedOrigin($request);
    }

    /**
     * Determine if the request's Origin header names an origin the app trusts.
     */
    protected function isTrustedOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost()
            || in_array($origin, $this->trustedOrigins(), true);
    }

    /**
     * Get the other origins the config trusts.
     *
     * @return list<string>
     */
    protected function trustedOrigins(): array
    {
        $origins = $this->config->get('keystone.trusted_origins');

        if (! is_array($origins) || ! array_is_list($origins)) {
            return [];
        }

        if (! array_all($origins, $this->isOrigin(...))) {
            return [];
        }

        return $origins;
    }

    /**
     * Determine if the value is a serialized origin: a scheme and a host, with nothing after them.
     */
    protected function isOrigin(mixed $value): bool
    {
        return is_string($value) && preg_match('~^https?://[^\s/?#@\\\\]+$~D', $value) === 1;
    }
}

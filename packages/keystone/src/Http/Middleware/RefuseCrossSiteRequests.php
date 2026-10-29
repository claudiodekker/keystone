<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\HardeningSettings;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
class RefuseCrossSiteRequests
{
    /**
     * Create the middleware.
     */
    public function __construct(
        protected Encrypter $encrypter,
        protected HardeningSettings $settings,
        protected Recorder $recorder,
    ) {
        //
    }

    /**
     * Let the request through when it is safe, else record it and refuse it as Laravel refuses a CSRF mismatch.
     *
     * @throws TokenMismatchException
     */
    public function handle(Request $request, Closure $next): Response
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
     * Determine if the request only reads.
     */
    protected function isReading(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
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
     * Determine if the request carries the session's CSRF token.
     */
    protected function tokensMatch(Request $request): bool
    {
        $token = $this->tokenFrom($request);

        return $token !== null && hash_equals($request->session()->token(), $token);
    }

    /**
     * Get the CSRF token the request carries in a field or header.
     */
    protected function tokenFrom(Request $request): ?string
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if ($token) {
            return is_string($token) ? $token : null;
        }

        $header = $request->header('X-XSRF-TOKEN');

        if (! $header) {
            return null;
        }

        try {
            $value = $this->encrypter->decrypt($header, PreventRequestForgery::serialized());
        } catch (DecryptException) {
            return null;
        }

        return CookieValuePrefix::remove($value);
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
     * Get the other origins the config trusts, none when the key isn't well formed.
     *
     * @return list<string>
     */
    protected function trustedOrigins(): array
    {
        return $this->settings->trustedOrigins() ?? [];
    }
}

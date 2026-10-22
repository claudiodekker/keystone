<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\ContentSecurityPolicy;
use ClaudioDekker\Keystone\Http\Controllers\KeystoneController;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the hardening floor over whatever a Keystone route's response carries, a redirect, a throttle, a rendered exception or a middleware's own answer included.
 *
 * @internal
 */
class ApplyHardeningFloor
{
    /**
     * The headers the floor sets, each over any value the app set.
     *
     * @var array<string, string>
     */
    protected const array HEADERS = [
        'Cache-Control' => 'no-store, max-age=0, must-revalidate',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * Create the middleware.
     */
    public function __construct(
        protected Config $config,
    ) {
        //
    }

    /**
     * Run the request, then harden the response if the route is Keystone's.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! static::covers($request)) {
            return $response;
        }

        $sources = $this->frameAncestors();

        $this->setHeaders($response);
        $this->restrictFraming($response, $sources);
        $this->mergePolicies($response, $sources);

        return $response;
    }

    /**
     * Determine if the request's route is Keystone's, by its controller class.
     */
    public static function covers(Request $request): bool
    {
        $controller = $request->route()?->getControllerClass();

        return $controller !== null && is_a($controller, KeystoneController::class, true);
    }

    /**
     * Set the floor's fixed headers over the app's.
     */
    protected function setHeaders(Response $response): void
    {
        foreach (static::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }
    }

    /**
     * Refuse every frame, or drop X-Frame-Options when some sources may frame Keystone's pages.
     *
     * @param  list<string>  $sources
     */
    protected function restrictFraming(Response $response, array $sources): void
    {
        if ($sources === []) {
            $response->headers->set('X-Frame-Options', 'DENY');

            return;
        }

        $response->headers->remove('X-Frame-Options');
    }

    /**
     * Close object-src, base-uri and frame-ancestors in each policy the response carries, keeping every other directive the app set.
     *
     * @param  list<string>  $sources
     */
    protected function mergePolicies(Response $response, array $sources): void
    {
        $policies = [];

        $headers = array_filter($response->headers->all('content-security-policy'), is_string(...));

        foreach ($headers ?: [''] as $header) {
            foreach (ContentSecurityPolicy::parseList($header) as $policy) {
                $policies[] = $this->harden($policy, $sources)->toString();
            }
        }

        $response->headers->set('Content-Security-Policy', $policies);
    }

    /**
     * Get the policy with object-src and base-uri closed, and frame-ancestors closed to all but the sources.
     *
     * @param  list<string>  $sources
     */
    protected function harden(ContentSecurityPolicy $policy, array $sources): ContentSecurityPolicy
    {
        return $policy
            ->with('base-uri', "'none'")
            ->with('frame-ancestors', $sources === [] ? "'none'" : implode(' ', $sources))
            ->with('object-src', "'none'");
    }

    /**
     * Get the CSP sources the config lets frame Keystone's pages.
     *
     * @return list<string>
     */
    protected function frameAncestors(): array
    {
        $sources = $this->config->get('keystone.hardening.frame_ancestors');

        if (! is_array($sources) || ! array_is_list($sources)) {
            return [];
        }

        if (! array_all($sources, $this->isSource(...))) {
            return [];
        }

        return $sources;
    }

    /**
     * Determine if the value is a single CSP source expression.
     */
    protected function isSource(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[^\s;,]+$/D', $value) === 1;
    }
}

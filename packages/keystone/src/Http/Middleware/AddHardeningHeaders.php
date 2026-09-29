<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\Middleware\Concerns\RecognizesKeystoneRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class AddHardeningHeaders
{
    use RecognizesKeystoneRoutes;

    /**
     * The headers every Keystone response carries, over any value the app gave them.
     */
    public const array HEADERS = [
        'Cache-Control' => 'no-store, max-age=0, must-revalidate',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
    ];

    /**
     * The Content-Security-Policy directives forced over the app's own.
     */
    public const array POLICY_DIRECTIVES = [
        'object-src' => "'none'",
        'base-uri' => "'none'",
        'frame-ancestors' => "'none'",
    ];

    /**
     * Attach the hardening floor to a Keystone response, whatever answered the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->routesToKeystone($request)) {
            return $response;
        }

        $response->headers->add(self::HEADERS);

        $policies = $response->headers->all('Content-Security-Policy') ?: [''];
        $hardened = array_map($this->hardenPolicy(...), $policies);

        $response->headers->set('Content-Security-Policy', $hardened);

        return $response;
    }

    /**
     * Keep the policy's own directives, replacing any the floor forces.
     */
    protected function hardenPolicy(?string $policy): string
    {
        $directives = array_filter(
            array_map(trim(...), explode(';', (string) $policy)),
            fn (string $directive) => $directive !== '' && ! array_key_exists($this->directiveName($directive), self::POLICY_DIRECTIVES),
        );

        foreach (self::POLICY_DIRECTIVES as $name => $value) {
            $directives[] = "{$name} {$value}";
        }

        return implode('; ', $directives);
    }

    /**
     * Get the name a policy directive starts with.
     */
    protected function directiveName(string $directive): string
    {
        $parts = preg_split('/\s+/', $directive, 2) ?: [''];

        return strtolower($parts[0]);
    }
}

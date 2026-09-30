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
    ];

    /**
     * The Content-Security-Policy directives forced over the app's own, besides frame-ancestors.
     */
    public const array POLICY_DIRECTIVES = [
        'object-src' => "'none'",
        'base-uri' => "'none'",
    ];

    /**
     * What a configured frame ancestor must look like: one CSP source, with nothing that could end the directive.
     */
    protected const string FRAME_ANCESTOR_PATTERN = '/^[^\s;,]+$/';

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

        $frameAncestors = $this->frameAncestors();
        $forced = [
            ...self::POLICY_DIRECTIVES,
            'frame-ancestors' => $frameAncestors === [] ? "'none'" : implode(' ', $frameAncestors),
        ];

        $response->headers->add(self::HEADERS);

        if ($frameAncestors === []) {
            $response->headers->set('X-Frame-Options', 'DENY');
        } else {
            $response->headers->remove('X-Frame-Options');
        }

        $policies = $response->headers->all('Content-Security-Policy') ?: [''];
        $hardened = array_map(fn (?string $policy) => $this->hardenPolicy($policy, $forced), $policies);

        $response->headers->set('Content-Security-Policy', $hardened);

        return $response;
    }

    /**
     * Get the sources keystone.hardening.frame_ancestors lets frame Keystone's pages; none unless every one is a valid source.
     *
     * @return list<string>
     */
    protected function frameAncestors(): array
    {
        $sources = config('keystone.hardening.frame_ancestors');

        if (! is_array($sources) || ! array_is_list($sources)) {
            return [];
        }

        foreach ($sources as $source) {
            if (! is_string($source) || ! static::isFrameAncestor($source)) {
                return [];
            }
        }

        return $sources;
    }

    /**
     * Determine if the value is a single CSP source a frame-ancestors directive can list.
     */
    public static function isFrameAncestor(string $source): bool
    {
        return preg_match(self::FRAME_ANCESTOR_PATTERN, $source) === 1;
    }

    /**
     * Keep the policy's own directives, replacing any the floor forces.
     *
     * @param  array<string, string>  $forced
     */
    protected function hardenPolicy(?string $policy, array $forced): string
    {
        $directives = array_filter(
            array_map(trim(...), explode(';', (string) $policy)),
            fn (string $directive) => $directive !== '' && ! array_key_exists($this->directiveName($directive), $forced),
        );

        foreach ($forced as $name => $value) {
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

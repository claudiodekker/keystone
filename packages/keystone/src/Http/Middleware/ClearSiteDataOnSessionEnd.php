<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\KeystoneGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class ClearSiteDataOnSessionEnd
{
    /**
     * The kinds of site data cleared unless keystone.clear_site_data names others.
     */
    public const array DEFAULT_TYPES = ['cache', 'storage'];

    /**
     * The kinds of site data keystone.clear_site_data may name.
     */
    protected const array TYPES = ['cache', 'cookies', 'storage', 'executionContexts'];

    /**
     * Tell the browser to clear the site's data when Keystone ended the session during the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $types = $this->types();

        if ($types !== [] && $request->attributes->getBoolean(KeystoneGuard::ENDED_SESSION)) {
            $quoted = array_map(fn (string $type) => "\"{$type}\"", $types);

            $response->headers->set('Clear-Site-Data', implode(', ', $quoted));
        }

        return $response;
    }

    /**
     * Get the kinds of site data to clear: those keystone.clear_site_data names, or the defaults when it names an unknown one.
     *
     * @return list<string>
     */
    protected function types(): array
    {
        $types = config('keystone.clear_site_data', self::DEFAULT_TYPES);

        if (! is_array($types) || ! array_is_list($types)) {
            return self::DEFAULT_TYPES;
        }

        foreach ($types as $type) {
            if (! in_array($type, self::TYPES, true)) {
                return self::DEFAULT_TYPES;
            }
        }

        /** @var list<string> $types */
        return $types;
    }
}

<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\HardeningSettings;
use ClaudioDekker\Keystone\Http\SiteData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class ClearSiteData
{
    /**
     * The kinds an ended session clears when the config names none it can honour.
     *
     * @var list<SiteData>
     */
    protected const array DEFAULT_KINDS = [
        SiteData::CACHE,
        SiteData::STORAGE,
    ];

    /**
     * Create the middleware.
     */
    public function __construct(
        protected HardeningSettings $settings,
    ) {
        //
    }

    /**
     * Clear the browser's site data with the response to the request, since Keystone ended its session.
     */
    public static function forResponseTo(SymfonyRequest $request): void
    {
        $request->attributes->set(static::class, true);
    }

    /**
     * Run the request, then add Clear-Site-Data if Keystone ended the session during it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->attributes->has(static::class)) {
            return $response;
        }

        $kinds = $this->kinds();

        if ($kinds === []) {
            return $response;
        }

        $response->headers->set('Clear-Site-Data', $this->header($kinds));

        return $response;
    }

    /**
     * Get the kinds of site data an ended session clears.
     *
     * @return list<SiteData>
     */
    protected function kinds(): array
    {
        return $this->settings->siteData() ?? static::DEFAULT_KINDS;
    }

    /**
     * Get the header value naming the kinds, each quoted.
     *
     * @param  list<SiteData>  $kinds
     */
    protected function header(array $kinds): string
    {
        return implode(', ', array_map(fn (SiteData $kind) => "\"{$kind->value}\"", $kinds));
    }
}

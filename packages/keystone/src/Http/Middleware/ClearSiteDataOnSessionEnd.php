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
     * Tell the browser to clear the site's cache and storage when Keystone ended the session during the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->attributes->getBoolean(KeystoneGuard::ENDED_SESSION)) {
            $response->headers->set('Clear-Site-Data', '"cache", "storage"');
        }

        return $response;
    }
}

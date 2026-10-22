<?php

namespace ClaudioDekker\Keystone\Http\Middleware;

use ClaudioDekker\Keystone\Http\Controllers\KeystoneController;
use Closure;
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
        'Content-Security-Policy' => "base-uri 'none'; frame-ancestors 'none'; object-src 'none'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ];

    /**
     * Run the request, then harden the response if the route is Keystone's.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! static::covers($request)) {
            return $response;
        }

        foreach (static::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Determine if the request's route is served by one of Keystone's controllers, or the app's subclass of one.
     */
    public static function covers(Request $request): bool
    {
        $controller = $request->route()?->getControllerClass();

        return $controller !== null && is_a($controller, KeystoneController::class, true);
    }
}

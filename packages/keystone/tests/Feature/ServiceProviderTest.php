<?php

use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\KeystoneServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Router;

it('boots in an app that discovers it', function () {
    expect($this->app->providerIsLoaded(KeystoneServiceProvider::class))->toBeTrue();
});

it('captures the request context in global middleware after the trusted-proxy middleware', function () {
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware)->toContain(CaptureRequestContext::class)
        ->and(array_search(CaptureRequestContext::class, $middleware, true))->toBeGreaterThan(array_search(TrustProxies::class, $middleware, true));
});

it('hardens responses from the start of the global middleware, before the trusted-proxy middleware', function () {
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware)->toContain(ApplyHardeningFloor::class)
        ->and(array_search(ApplyHardeningFloor::class, $middleware, true))->toBeLessThan(array_search(TrustProxies::class, $middleware, true));
});

it("checks cross-site requests in the web group after Laravel's own CSRF check", function () {
    $web = $this->app->make(Router::class)->getMiddlewareGroups()['web'];

    expect($web)->toContain(RefuseCrossSiteRequests::class)
        ->and(array_search(RefuseCrossSiteRequests::class, $web, true))->toBeGreaterThan(array_search(PreventRequestForgery::class, $web, true));
});

it('clears site data from the start of the global middleware, before the trusted-proxy middleware', function () {
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware)->toContain(ClearSiteData::class)
        ->and(array_search(ClearSiteData::class, $middleware, true))->toBeLessThan(array_search(TrustProxies::class, $middleware, true));
});

<?php

use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\KeystoneServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;

it('boots in an app that discovers it', function () {
    expect($this->app->providerIsLoaded(KeystoneServiceProvider::class))->toBeTrue();
});

it('captures the request context in global middleware after the trusted-proxy middleware', function () {
    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware)->toContain(CaptureRequestContext::class)
        ->and(array_search(CaptureRequestContext::class, $middleware, true))->toBeGreaterThan(array_search(TrustProxies::class, $middleware, true));
});

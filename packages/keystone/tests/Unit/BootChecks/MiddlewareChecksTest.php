<?php

use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;

it('boots with the request context middleware in the HTTP kernel', function () {
    expect($this->bootFailures())->toBe([]);
});

it('refuses an HTTP kernel that does not run the request context middleware', function () {
    $this->app->make(Kernel::class)->setGlobalMiddleware([TrustProxies::class]);

    expect($this->bootFailures())->toBe(["The HTTP kernel doesn't run [".CaptureRequestContext::class.'] as global middleware, which Keystone needs to read the request. Add it back to the global middleware.']);
});

it('boots with an HTTP kernel whose global middleware it cannot read', function () {
    $this->app->instance(Kernel::class, $this->createStub(Kernel::class));

    expect($this->bootFailures())->toBe([]);
});

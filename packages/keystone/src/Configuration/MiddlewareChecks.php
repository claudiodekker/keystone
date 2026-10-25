<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * @internal
 */
class MiddlewareChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected Kernel $kernel,
    ) {
        //
    }

    /**
     * Get the failure of the HTTP kernel when it doesn't run the middleware that captures the request context.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        if (! $this->kernel instanceof HttpKernel) {
            return [];
        }

        if (in_array(CaptureRequestContext::class, $this->kernel->getGlobalMiddleware(), true)) {
            return [];
        }

        return ["The HTTP kernel doesn't run [".CaptureRequestContext::class.'] as global middleware, which Keystone needs to read the request. Add it back to the global middleware.'];
    }
}

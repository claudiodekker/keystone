<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Http\HardeningSettings;
use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Http\Middleware\FlashOnlyTheIdentifier;
use ClaudioDekker\Keystone\Http\Middleware\LimitRequests;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\SignIn\SignIn;
use ClaudioDekker\Keystone\SignIn\SignInDecision;
use ClaudioDekker\Keystone\SignIn\TimingFloor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Check that the app leaves Keystone's internal services to Keystone and runs the middleware Keystone needs.
 *
 * @internal
 */
class ServiceChecks
{
    /**
     * The internal services the container builds for core: every class core injects, and every middleware it runs.
     *
     * @var list<class-string>
     */
    protected const array SERVICES = [
        AccountLookup::class,
        ApplyHardeningFloor::class,
        BootChecks::class,
        CaptureRequestContext::class,
        ClearSiteData::class,
        FlashOnlyTheIdentifier::class,
        GuardSetup::class,
        HardeningSettings::class,
        KeyChecks::class,
        LimitRequests::class,
        Limiter::class,
        MethodChecks::class,
        ModelChecks::class,
        ProductionChecks::class,
        Recorder::class,
        RefuseCrossSiteRequests::class,
        ServiceChecks::class,
        SessionState::class,
        SignIn::class,
        SignInDecision::class,
        TimingFloor::class,
    ];

    /**
     * Create the checks.
     */
    public function __construct(
        protected Application $app,
        protected Kernel $kernel,
    ) {
        //
    }

    /**
     * Get the failure of each way the app's wiring takes a decision from Keystone.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return [
            ...$this->boundServices(),
            ...$this->requestContext(),
        ];
    }

    /**
     * Get the failure of each internal service the app binds.
     *
     * @return list<string>
     */
    protected function boundServices(): array
    {
        $bound = array_filter(static::SERVICES, fn (string $service) => $this->app->bound($service));

        return array_map(
            fn (string $service) => "The app binds [{$service}], one of Keystone's internal services. Remove the binding, since Keystone makes its security decisions with its own.",
            array_values($bound),
        );
    }

    /**
     * Get the failure of the HTTP kernel when it doesn't run the middleware that captures the request context.
     *
     * @return list<string>
     */
    protected function requestContext(): array
    {
        $global = $this->kernel instanceof HttpKernel ? $this->kernel->getGlobalMiddleware() : [];

        if (in_array(CaptureRequestContext::class, $global, true)) {
            return [];
        }

        return ["The HTTP kernel doesn't run [".CaptureRequestContext::class.'] as global middleware, which Keystone needs to read the request. Add it back to the global middleware.'];
    }
}

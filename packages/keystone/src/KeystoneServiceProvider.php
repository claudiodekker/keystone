<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Register Keystone with the app.
 *
 * @internal
 */
class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * Merge the config, and start the app from no registered credential types, so each app registers its own in boot().
     */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/keystone.php', 'keystone');

        Keystone::flushState();
    }

    /**
     * Boot Keystone in the app.
     */
    public function boot(): void
    {
        Auth::extend('keystone', $this->createGuard(...));

        $this->captureRequestContext();
        $this->hardenResponses();
        $this->refuseCrossSiteRequests();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/lang', 'keystone');
    }

    /**
     * Add the request context middleware to the end of the global middleware, so it runs after the app's trusted-proxy middleware.
     */
    protected function captureRequestContext(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(CaptureRequestContext::class);
    }

    /**
     * Add the hardening floor to the start of the global middleware, so it sees the final response of every other layer.
     */
    protected function hardenResponses(): void
    {
        $this->app->make(Kernel::class)->prependMiddleware(ApplyHardeningFloor::class);
    }

    /**
     * Add the cross-site check to the end of the web group, so it runs once the session has started and after Laravel's own CSRF check.
     */
    protected function refuseCrossSiteRequests(): void
    {
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RefuseCrossSiteRequests::class);
    }

    /**
     * Create the keystone guard the way Laravel creates its session guard.
     *
     * @param  array{provider?: string}  $config
     */
    protected function createGuard(Application $app, string $name, array $config): KeystoneGuard
    {
        $provider = Auth::createUserProvider($config['provider'] ?? null);

        if ($provider === null) {
            throw KeystoneMisconfiguredException::missingUserProvider($name);
        }

        $guard = new KeystoneGuard($name, $provider, $app->make('session.store'));

        $guard->setCookieJar($app->make('cookie'));
        $guard->setDispatcher($app->make('events'));
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        return $guard;
    }
}

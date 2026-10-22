<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
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
        $this->mergeConfigRecursivelyFrom(dirname(__DIR__).'/config/keystone.php', 'keystone');

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
     * Merge the app's config over the file at the path, so a key added later keeps its default.
     *
     * A cached config was merged when it was cached, so it is left as cached.
     */
    protected function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $core = require $path;

        $config->set($key, $this->mergeRecursively($core, $config->get($key, [])));
    }

    /**
     * Get core's array with the app's laid over it: maps merge key by key, and any other value of the app's replaces core's.
     *
     * @param  array<array-key, mixed>  $core
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    protected function mergeRecursively(array $core, array $app): array
    {
        foreach ($app as $name => $value) {
            $default = $core[$name] ?? null;

            $core[$name] = $this->isMap($default) && $this->mergesIntoMap($value)
                ? $this->mergeRecursively($default, $value)
                : $value;
        }

        return $core;
    }

    /**
     * Determine if the value is an array with keys.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    protected function isMap(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    /**
     * Determine if the value is an array that can merge into a map, an empty one included.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    protected function mergesIntoMap(mixed $value): bool
    {
        return $value === [] || $this->isMap($value);
    }

    /**
     * Add the request context middleware to the end of the global middleware, so it runs after the app's trusted-proxy middleware.
     */
    protected function captureRequestContext(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(CaptureRequestContext::class);
    }

    /**
     * Add the hardening floor and Clear-Site-Data to the start of the global middleware, so they see the final response of every other layer.
     */
    protected function hardenResponses(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $kernel->prependMiddleware(ApplyHardeningFloor::class);
        $kernel->prependMiddleware(ClearSiteData::class);
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

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Configuration\BootChecks;
use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\Http\Responses\UnauthenticatedResponse;
use ClaudioDekker\Keystone\Http\SessionExpiry;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * The console commands that run without the boot checks.
     *
     * @var list<string>
     */
    protected const array COMMANDS_WITHOUT_BOOT_CHECKS = ['config:clear', 'optimize:clear'];

    /**
     * Merge the config, and bind the credential types the method packages register in boot().
     */
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(dirname(__DIR__).'/config/keystone.php', 'keystone');

        $this->app->singleton(CredentialTypeRegistry::class);

        $this->app->bindIf(SessionExpiredResponse::class, UnauthenticatedResponse::class);

        $this->app->bind(CredentialTypes::class, fn (Application $app) => new CredentialTypes(
            $app->make(CredentialTypeRegistry::class)->types(),
            MethodAllowList::from($app->make('config')->get('keystone.methods')),
        ));
    }

    /**
     * Boot Keystone in the app.
     */
    public function boot(): void
    {
        Auth::extend('keystone', $this->createGuard(...));

        $this->answerExpiredSessions();
        $this->captureRequestContext();
        $this->hardenResponses();
        $this->refuseCrossSiteRequests();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/lang', 'keystone');

        $this->checkBoot();
    }

    /**
     * Merge the app's config over the file at the path, so a key added later keeps its default.
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
     * Run the boot checks once the app has booted, unless the config comes from the cache, where they ran when it was cached, or the command clears it.
     */
    protected function checkBoot(): void
    {
        $this->app->booted(function () {
            if ($this->app->configurationIsCached()) {
                return;
            }

            if ($this->app->runningConsoleCommand(...static::COMMANDS_WITHOUT_BOOT_CHECKS)) {
                return;
            }

            $this->app->make(BootChecks::class)->run();
        });
    }

    /**
     * Render the app's expiry response, through Laravel's exception handler, for a request that needs a user whose session expired during it.
     */
    protected function answerExpiredSessions(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (AuthenticationException $exception, Request $request) {
                $expiry = $this->app->make(SessionExpiry::class);

                return $expiry->render($exception, $request);
            });
        });
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

        $guard->setContainer($app);
        $guard->setCookieJar($app->make('cookie'));
        $guard->setDispatcher($app->make('events'));
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        return $guard;
    }
}

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Actions\RespondToDemotedSession;
use ClaudioDekker\Keystone\Actions\RespondToExpiredSession;
use ClaudioDekker\Keystone\Console\EndSessionsCommand;
use ClaudioDekker\Keystone\Console\SuspendCommand;
use ClaudioDekker\Keystone\Console\UnsuspendCommand;
use ClaudioDekker\Keystone\Http\Middleware\AddHardeningHeaders;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteDataOnSessionEnd;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use DeviceDetector\DeviceDetector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Stevebauman\Location\LocationManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * The password fields a proof or a new password arrives in, which are never trimmed.
     */
    protected const array PASSWORD_FIELDS = ['password', 'current_password', 'password_confirmation'];

    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/keystone.php', 'keystone');

        $this->app->singleton(CredentialTypes::class);
        $this->app->bindIf(AccountLookup::class);
        $this->app->bindIf(RespondToExpiredSession::class);
        $this->app->bindIf(RespondToDemotedSession::class);
        $this->app->scoped(RequestContext::class, fn () => new RequestContext);
        $this->app->bindIf(IpLocation::class, fn () => class_exists(LocationManager::class) ? new StevebaumanIpLocation : new NullIpLocation);
        $this->app->bindIf(SessionInfo::class, fn () => class_exists(DeviceDetector::class) ? new DeviceDetectorSessionInfo : new NullSessionInfo);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(AuthManager $auth, Kernel $kernel, Router $router): void
    {
        $auth->extend('keystone', $this->createGuard(...));

        TrimStrings::except(self::PASSWORD_FIELDS);

        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(CaptureRequestContext::class);
            $kernel->prependMiddleware(ClearSiteDataOnSessionEnd::class);
            $kernel->prependMiddleware(AddHardeningHeaders::class);
        }

        $router->pushMiddlewareToGroup('web', RefuseCrossSiteRequests::class);

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if ($handler instanceof Handler) {
                $handler->renderable($this->renderExpiredSession(...));
                $handler->renderable($this->renderDemotedSession(...));
            }
        });

        $this->app->booted(fn (Application $app) => (new BootChecks($app->make(CredentialTypes::class)))->check());

        if ($this->app->runningInConsole()) {
            $this->commands([
                EndSessionsCommand::class,
                SuspendCommand::class,
                UnsuspendCommand::class,
            ]);
        }

        $this->publishes([__DIR__.'/../config/keystone.php' => config_path('keystone.php')], 'keystone-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'keystone');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'keystone');
    }

    /**
     * Merge the app's config over the package's: maps merge key by key, and anything else the app sets replaces the default whole.
     */
    protected function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, $this->mergeOver(defaults: require $path, values: $config->get($key, [])));
    }

    /**
     * Merge the values over the defaults, recursing into every map the defaults hold.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function mergeOver(array $defaults, array $values): array
    {
        foreach ($values as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = $this->isMap($default) && ($value === [] || $this->isMap($value))
                ? $this->mergeOver($default, $value)
                : $value;
        }

        return $defaults;
    }

    /**
     * Determine if the value is an array keyed by name.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    protected function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * Respond to a request whose session expired, leaving every other unauthenticated request to the app's handler.
     */
    protected function renderExpiredSession(AuthenticationException $e, Request $request): ?Response
    {
        if (! $request->attributes->getBoolean(KeystoneGuard::EXPIRED_SESSION)) {
            return null;
        }

        return $this->app->make(RespondToExpiredSession::class)->handle($request, $e);
    }

    /**
     * Respond to a request whose signed-in session was held back at enrollment, leaving every other unauthenticated request to the app's handler.
     */
    protected function renderDemotedSession(AuthenticationException $e, Request $request): ?Response
    {
        if (! $request->attributes->getBoolean(KeystoneGuard::DEMOTED_SESSION)) {
            return null;
        }

        return $this->app->make(RespondToDemotedSession::class)->handle($request, $e);
    }

    /**
     * Create the keystone guard.
     *
     * @param  array{provider?: string}  $config
     */
    protected function createGuard(Application $app, string $name, array $config): KeystoneGuard
    {
        $auth = $app->make('auth');
        $providerName = $config['provider'] ?? $auth->getDefaultUserProvider();
        $provider = $auth->createUserProvider($providerName);

        if (! $provider instanceof EloquentUserProvider) {
            throw new LogicException("auth.providers.{$providerName} must use the eloquent driver.");
        }

        if (! $provider->createModel() instanceof KeystoneUser) {
            throw new LogicException("auth.providers.{$providerName}.model must implement ".KeystoneUser::class.'.');
        }

        $guard = new KeystoneGuard($name, $provider, $app->make('session.store'));

        $guard->setCookieJar($app->make('cookie'));
        $guard->setDispatcher($app->make('events'));
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        return $guard;
    }
}

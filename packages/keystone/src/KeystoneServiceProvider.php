<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Actions\RespondToDemotedSession;
use ClaudioDekker\Keystone\Actions\RespondToExpiredSession;
use ClaudioDekker\Keystone\Actions\RespondToSudoRequired;
use ClaudioDekker\Keystone\Console\EndSessionsCommand;
use ClaudioDekker\Keystone\Console\SuspendCommand;
use ClaudioDekker\Keystone\Console\UnsuspendCommand;
use ClaudioDekker\Keystone\Http\Middleware\AddHardeningHeaders;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteDataOnSessionEnd;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\Http\Middleware\RequireSudo;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use DeviceDetector\DeviceDetector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
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
    use MergesConfigRecursively;

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
        $this->app->bindIf(RespondToSudoRequired::class);
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

        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(CaptureRequestContext::class);
            $kernel->prependMiddleware(ClearSiteDataOnSessionEnd::class);
            $kernel->prependMiddleware(AddHardeningHeaders::class);
        }

        $router->pushMiddlewareToGroup('web', RefuseCrossSiteRequests::class);
        $router->aliasMiddleware('sudo', RequireSudo::class);

        EncryptCookies::except([KnownDevices::COOKIE, RememberTokens::COOKIE]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => KnownDevices::prune(Keystone::guard()->userModel()))
                ->name('keystone:prune-known-devices')
                ->daily()
                ->onOneServer();

            $schedule->call(fn () => EmailedLinks::prune(Keystone::guard()->userModel()))
                ->name('keystone:prune-used-email-links')
                ->hourly()
                ->onOneServer();

            $schedule->call(fn () => (new PendingChallenges(Keystone::guard()->userModel()))->sweep())
                ->name('keystone:sweep-abandoned-challenges')
                ->everyFiveMinutes()
                ->withoutOverlapping(15)
                ->onOneServer();
        });

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
     * Respond to a request whose session ended because its account newly owes enrollment, leaving every other unauthenticated request to the app's handler.
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

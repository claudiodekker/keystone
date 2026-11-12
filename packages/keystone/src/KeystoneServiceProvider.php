<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Configuration\BootChecks;
use ClaudioDekker\Keystone\Console\AlertAbandonedChallengesCommand;
use ClaudioDekker\Keystone\Console\EndSessionsCommand;
use ClaudioDekker\Keystone\Console\PruneCommand;
use ClaudioDekker\Keystone\Console\SuspendCommand;
use ClaudioDekker\Keystone\Console\UnsuspendCommand;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Contracts\SessionDemotedResponse;
use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Contracts\SudoRequiredResponse;
use ClaudioDekker\Keystone\Devices\DeviceCookie;
use ClaudioDekker\Keystone\Devices\DeviceDetectorSessionInfo;
use ClaudioDekker\Keystone\Devices\NullSessionInfo;
use ClaudioDekker\Keystone\Exceptions\KeystoneMisconfiguredException;
use ClaudioDekker\Keystone\Exceptions\SudoRequiredException;
use ClaudioDekker\Keystone\Http\EndedSession;
use ClaudioDekker\Keystone\Http\Middleware\ApplyHardeningFloor;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteData;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\Http\Middleware\RequireSudo;
use ClaudioDekker\Keystone\Http\Responses\SudoPageRedirect;
use ClaudioDekker\Keystone\Http\Responses\UnauthenticatedResponse;
use ClaudioDekker\Keystone\Locations\NullIpLocation;
use ClaudioDekker\Keystone\Locations\StevebaumanIpLocation;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use ClaudioDekker\Keystone\Session\RememberCookie;
use DeviceDetector\DeviceDetector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Stevebauman\Location\LocationManager;

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

        $this->app->bindIf(SessionDemotedResponse::class, fn (Application $app) => new UnauthenticatedResponse(
            $app->make(Redirector::class),
            Status::SESSION_DEMOTED,
            reason: 'demoted',
        ));

        $this->app->bindIf(SessionExpiredResponse::class, fn (Application $app) => new UnauthenticatedResponse(
            $app->make(Redirector::class),
            Status::SESSION_EXPIRED,
            reason: 'expired',
        ));

        $this->app->bindIf(SudoRequiredResponse::class, SudoPageRedirect::class);

        $this->app->bindIf(IpLocation::class, $this->ipLocation());
        $this->app->bindIf(SessionInfo::class, $this->sessionInfo());

        $this->app->bind(CredentialTypes::class, fn (Application $app) => new CredentialTypes(
            $app->make(CredentialTypeRegistry::class)->types(),
            MethodAllowList::from($app->make('config')->get('keystone.methods')),
        ));
    }

    /**
     * Get the IP location to bind: the stevebauman/location adapter when the app installed that package, or none.
     *
     * @return class-string<IpLocation>
     */
    protected function ipLocation(): string
    {
        return class_exists(LocationManager::class) ? StevebaumanIpLocation::class : NullIpLocation::class;
    }

    /**
     * Get the session info to bind: the matomo/device-detector adapter when the app installed that package, or none.
     *
     * @return class-string<SessionInfo>
     */
    protected function sessionInfo(): string
    {
        return class_exists(DeviceDetector::class) ? DeviceDetectorSessionInfo::class : NullSessionInfo::class;
    }

    /**
     * Boot Keystone in the app.
     */
    public function boot(): void
    {
        Auth::extend('keystone', $this->createGuard(...));

        $this->answerEndedSessions();
        $this->captureRequestContext();
        $this->excludeKeystoneCookiesFromEncryption();
        $this->hardenResponses();
        $this->refuseCrossSiteRequests();
        $this->registerSudoGate();
        $this->schedule();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/lang', 'keystone');
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'keystone');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AlertAbandonedChallengesCommand::class,
                EndSessionsCommand::class,
                PruneCommand::class,
                SuspendCommand::class,
                UnsuspendCommand::class,
            ]);
        }

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
     * Answer a request that needs a user whose session Keystone ended during it with the app's expiry or demotion response.
     */
    protected function answerEndedSessions(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (AuthenticationException $exception, Request $request) {
                $ended = $this->app->make(EndedSession::class);

                return $ended->render($exception, $request);
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
     * Schedule core's maintenance commands, each on one server, so the app registers nothing.
     *
     * The sweep's overlap lock lasts 10 minutes rather than the scheduler's day, so a sweep that died holding it delays the alerts by one run.
     */
    protected function schedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(AlertAbandonedChallengesCommand::class)->everyFiveMinutes()->onOneServer()->withoutOverlapping(expiresAt: 10);
            $schedule->command(PruneCommand::class)->daily()->onOneServer();
        });
    }

    /**
     * Leave the device and remember-me cookies unencrypted, since only their digests are checked and a value Keystone never handed out matches nothing.
     */
    protected function excludeKeystoneCookiesFromEncryption(): void
    {
        EncryptCookies::except([DeviceCookie::NAME, RememberCookie::NAME]);
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
     * Let apps gate their routes with the sudo middleware alias, and answer a request the gate refused with the app's response.
     */
    protected function registerSudoGate(): void
    {
        $this->app->make(Router::class)->aliasMiddleware(RequireSudo::ALIAS, RequireSudo::class);

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (SudoRequiredException $exception, Request $request) {
                $response = $this->app->make(SudoRequiredResponse::class)->toResponse($request, $exception);

                return Router::toResponse($request, $response);
            });
        });
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

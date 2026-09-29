<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Actions\AccountLookup;
use ClaudioDekker\Keystone\Http\Middleware\AddHardeningHeaders;
use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteDataOnSessionEnd;
use ClaudioDekker\Keystone\Http\Middleware\RefuseCrossSiteRequests;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;
use LogicException;

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
        $this->app->singleton(CredentialTypes::class);
        $this->app->bindIf(AccountLookup::class);
        $this->app->scoped(RequestContext::class, fn () => new RequestContext);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(AuthManager $auth, Kernel $kernel): void
    {
        $auth->extend('keystone', $this->createGuard(...));

        TrimStrings::except(self::PASSWORD_FIELDS);

        if ($kernel instanceof HttpKernel) {
            $kernel->pushMiddleware(CaptureRequestContext::class);
            $kernel->prependMiddleware(ClearSiteDataOnSessionEnd::class);
            $kernel->prependMiddleware(AddHardeningHeaders::class);
            $kernel->appendMiddlewareToGroup('web', RefuseCrossSiteRequests::class);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'keystone');
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

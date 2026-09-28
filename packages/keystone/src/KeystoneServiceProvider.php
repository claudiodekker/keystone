<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * @api
 */
class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the package services.
     */
    public function boot(AuthManager $auth): void
    {
        $auth->extend('keystone', $this->createGuard(...));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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

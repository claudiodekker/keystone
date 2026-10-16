<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Register Keystone with the app.
 *
 * @internal
 */
class KeystoneServiceProvider extends ServiceProvider
{
    /**
     * Register the keystone guard driver, the migrations and the translations.
     */
    public function boot(): void
    {
        Auth::extend('keystone', $this->createGuard(...));

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/lang', 'keystone');
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
            throw new LogicException("Keystone needs a user provider on the [{$name}] guard.");
        }

        $guard = new KeystoneGuard($name, $provider, $app->make('session.store'));

        $guard->setCookieJar($app->make('cookie'));
        $guard->setDispatcher($app->make('events'));
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        return $guard;
    }
}

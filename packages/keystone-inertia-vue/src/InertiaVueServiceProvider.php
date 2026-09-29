<?php

namespace ClaudioDekker\Keystone\InertiaVue;

use ClaudioDekker\Keystone\InertiaVue\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class InertiaVueServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}

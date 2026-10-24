<?php

namespace ClaudioDekker\Keystone\InertiaVue;

use ClaudioDekker\Keystone\InertiaVue\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
class InertiaVueServiceProvider extends ServiceProvider
{
    /**
     * Register the install command in the console.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}

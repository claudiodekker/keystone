<?php

namespace ClaudioDekker\Keystone\Configuration;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

/**
 * Read from the auth config what the default guard is set up as, without building the guard. A setting the config doesn't name comes back as null.
 *
 * @internal
 */
class GuardSetup
{
    /**
     * Create the setup.
     */
    public function __construct(
        protected Config $config,
    ) {
        //
    }

    /**
     * Get the driver of the default guard.
     */
    public function driver(): ?string
    {
        $driver = $this->config->get("auth.guards.{$this->name()}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * Get the class of the Eloquent model the default guard's provider reads.
     *
     * @return class-string<Model>|null
     */
    public function userModel(): ?string
    {
        $provider = $this->config->get("auth.guards.{$this->name()}.provider");
        $model = $this->config->get("auth.providers.{$provider}.model");

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }

    /**
     * Get the name of the default guard.
     */
    public function name(): string
    {
        return $this->config->string('auth.defaults.guard');
    }
}

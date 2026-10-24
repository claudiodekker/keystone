<?php

namespace ClaudioDekker\Keystone\Configuration;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Check that the models Keystone reads sit where its tables are migrated: on the default connection.
 *
 * @internal
 */
class ModelChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected Config $config,
        protected GuardSetup $guard,
    ) {
        //
    }

    /**
     * Get the failure of each model that sits on another connection.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return [
            ...$this->userModel(),
        ];
    }

    /**
     * Get the failure of the user model when it sits on a connection other than the default.
     *
     * An app whose default guard doesn't use the keystone driver has no user model to check, and the production checks refuse that setup.
     *
     * @return list<string>
     */
    protected function userModel(): array
    {
        $class = $this->guard->userModel();

        if ($class === null) {
            return [];
        }

        $model = new $class;
        $connection = $model->getConnectionName();
        $default = $this->config->string('database.default');

        if ($connection === null || $connection === $default) {
            return [];
        }

        return ["The user model [{$class}] is on the [{$connection}] connection, but Keystone's tables are on the default [{$default}] connection."];
    }
}

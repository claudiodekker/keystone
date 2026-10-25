<?php

namespace ClaudioDekker\Keystone\Configuration;

use Illuminate\Contracts\Config\Repository as Config;

/**
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
     * Get the failure of the user model when it sits on a connection other than the default.
     *
     * @return list<string>
     */
    public function failures(): array
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

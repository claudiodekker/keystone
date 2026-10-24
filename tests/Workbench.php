<?php

namespace ClaudioDekker\Keystone\Tests;

use InvalidArgumentException;

use function Orchestra\Testbench\default_skeleton_path;

class Workbench
{
    /**
     * Point Laravel at the workbench app for the named adapter and get its stub test case, or null when no adapter is named.
     *
     * The stub test case's createApplication() requires APP_BASE_PATH/bootstrap/app.php, which only Testbench's skeleton has.
     *
     * @return class-string|null
     */
    public static function boot(?string $adapter): ?string
    {
        if ($adapter === null) {
            return null;
        }

        if (! in_array($adapter, Monorepo::adapters(), true)) {
            throw new InvalidArgumentException("No adapter [{$adapter}]. The adapters are: ".implode(', ', Monorepo::adapters()).'.');
        }

        define('TESTBENCH_WORKING_PATH', dirname(__DIR__));
        $_ENV['APP_BASE_PATH'] = default_skeleton_path();

        return 'Tests\\Keystone\\TestCase';
    }
}

<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Point the guard at the keystone driver in config/auth.php.
 *
 * @internal
 */
class UseKeystoneGuard extends Edit
{
    /**
     * Create the edit of the named guard.
     */
    public function __construct(protected ?string $guard)
    {
        //
    }

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'config/auth.php';
    }

    /**
     * Get the file's contents with the guard on the keystone driver.
     */
    public function apply(string $contents): ?string
    {
        if ($this->guard === null) {
            return null;
        }

        $guard = preg_quote($this->guard, '/');
        $pattern = "/('{$guard}' => \[\s*'driver' => ')(\w+)(')/";

        if (preg_match($pattern, $contents, $match) !== 1 || ! in_array($match[2], ['keystone', 'session'], true)) {
            return null;
        }

        return preg_replace($pattern, '${1}keystone${3}', $contents, 1);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        if ($this->guard === null) {
            return "Set the driver of your default guard to 'keystone' in config/auth.php.";
        }

        return "Set auth.guards.{$this->guard}.driver to 'keystone' in config/auth.php.";
    }
}

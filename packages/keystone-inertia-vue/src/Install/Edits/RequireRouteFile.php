<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Require routes/keystone.php from routes/web.php.
 *
 * @internal
 */
class RequireRouteFile extends Edit
{
    /**
     * The statement that loads the route file.
     */
    protected const string REQUIRE = "require __DIR__.'/keystone.php';";

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'routes/web.php';
    }

    /**
     * Get the file's contents with the require appended.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, static::REQUIRE)) {
            return $contents;
        }

        return rtrim($contents)."\n\n".static::REQUIRE."\n";
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return 'Add '.static::REQUIRE.' to routes/web.php.';
    }
}

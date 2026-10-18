<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\AppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Bind the app's tests/Keystone/TestCase.php to every installed Keystone package's AppTests in tests/Pest.php.
 *
 * @internal
 */
class BindKeystoneTestCase extends Edit
{
    /**
     * The binding, with the AppTests path relative to tests/ as Pest reads it.
     */
    protected const string BINDING = "pest()->extend(Tests\\Keystone\\TestCase::class)->in('../".AppTests::GLOB."');";

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'tests/Pest.php';
    }

    /**
     * Get the file's contents with the Keystone binding.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, static::BINDING)) {
            return $contents;
        }

        if (preg_match('/^pest\(\)->extend\([^;]*;/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$binding, $offset] = $match[0];

        return substr_replace($contents, "\n\n".static::BINDING, $offset + strlen($binding), 0);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return "Add this binding to tests/Pest.php:\n\n".static::BINDING;
    }
}

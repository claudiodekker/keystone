<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\AppTests;
use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Register the Keystone testsuite in phpunit.xml, which reads every installed Keystone package's AppTests from vendor.
 *
 * @internal
 */
class RegisterKeystoneTestsuite extends Edit
{
    /**
     * The testsuite, as the skeleton indents one.
     */
    protected const string TESTSUITE = '        <testsuite name="Keystone">'."\n"
        .'            <directory>'.AppTests::GLOB.'</directory>'."\n"
        .'        </testsuite>';

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'phpunit.xml';
    }

    /**
     * Get the file's contents with the Keystone testsuite.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, '<testsuite name="Keystone">')) {
            return $contents;
        }

        if (preg_match('/^(\s*)<\/testsuites>$/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_replace($contents, static::TESTSUITE."\n", $match[0][1], 0);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return "Add this testsuite to phpunit.xml:\n\n".static::TESTSUITE;
    }
}

<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Stop the welcome page loading resources/js/app.js, which the setup moved aside.
 *
 * @internal
 */
class RemoveAppJsFromWelcome extends Edit
{
    /**
     * The skeleton's assets directive.
     */
    protected const string LOADING = "@vite(['resources/css/app.css', 'resources/js/app.js'])";

    /**
     * The assets directive without the script.
     */
    protected const string STYLESHEET_ONLY = "@vite(['resources/css/app.css'])";

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'resources/views/welcome.blade.php';
    }

    /**
     * Get the file's contents with the script dropped from the assets directive.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, static::LOADING)) {
            return str_replace(static::LOADING, static::STYLESHEET_ONLY, $contents);
        }

        return str_contains($contents, 'resources/js/app.js') ? null : $contents;
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return 'Stop loading resources/js/app.js in resources/views/welcome.blade.php, which is resources/js/app.js.bak now.';
    }
}

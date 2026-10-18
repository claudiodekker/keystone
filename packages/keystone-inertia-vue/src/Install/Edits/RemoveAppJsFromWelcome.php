<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;
use ClaudioDekker\Keystone\InertiaVue\Install\Frontend;

/**
 * Stop the welcome page loading resources/js/app.js.
 *
 * @internal
 */
class RemoveAppJsFromWelcome extends Edit
{
    /**
     * The skeleton's assets directive.
     */
    protected const string SKELETON_DIRECTIVE = "@vite(['resources/css/app.css', '".Frontend::APP_JS."'])";

    /**
     * The assets directive without the script.
     */
    protected const string STYLESHEET_ONLY = "@vite(['resources/css/app.css'])";

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return Frontend::WELCOME_VIEW;
    }

    /**
     * Get the file's contents with the script dropped from the assets directive.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, static::SKELETON_DIRECTIVE)) {
            return str_replace(static::SKELETON_DIRECTIVE, static::STYLESHEET_ONLY, $contents);
        }

        return str_contains($contents, Frontend::APP_JS) ? null : $contents;
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return 'Stop loading '.Frontend::APP_JS.' in '.Frontend::WELCOME_VIEW.', which is '.Frontend::APP_JS.'.bak now.';
    }
}

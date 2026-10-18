<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Append HandleInertiaRequests to the web middleware group in bootstrap/app.php.
 *
 * @internal
 */
class RegisterInertiaMiddleware extends Edit
{
    /**
     * The class name of the middleware the setup stubs put in the app.
     */
    protected const string NAME = 'HandleInertiaRequests';

    /**
     * The middleware the setup stubs put in the app.
     */
    protected const string MIDDLEWARE = 'App\\Http\\Middleware\\'.self::NAME;

    /**
     * The skeleton's middleware closure, with nothing registered.
     */
    protected const string EMPTY_CLOSURE = '->withMiddleware(function (Middleware $middleware): void {'."\n"
        .'        //'."\n"
        .'    })';

    /**
     * The middleware closure with the middleware appended to the web group.
     */
    protected const string APPENDING_CLOSURE = '->withMiddleware(function (Middleware $middleware): void {'."\n"
        .'        $middleware->web(append: ['."\n"
        .'            '.self::NAME.'::class,'."\n"
        .'        ]);'."\n"
        .'    })';

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return 'bootstrap/app.php';
    }

    /**
     * Get the file's contents with the middleware appended to the web group and imported.
     */
    public function apply(string $contents): ?string
    {
        if (str_contains($contents, static::NAME.'::class')) {
            return $this->import($contents, static::MIDDLEWARE);
        }

        if (! str_contains($contents, static::EMPTY_CLOSURE)) {
            return null;
        }

        $registered = str_replace(static::EMPTY_CLOSURE, static::APPENDING_CLOSURE, $contents);

        return $this->import($registered, static::MIDDLEWARE);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return 'Append '.static::MIDDLEWARE." to the web middleware group in bootstrap/app.php:\n\n    \$middleware->web(append: [".static::NAME.'::class]);';
    }
}

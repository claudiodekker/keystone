<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

/**
 * @internal
 */
enum FrontendEntry: string
{
    case STOCK = 'stock';
    case OWN = 'own';
    case UNKNOWN = 'unknown';

    /**
     * Determine if the installer sets up Inertia and Vue itself in the app.
     */
    public function isSetUpByKeystone(): bool
    {
        return $this === self::STOCK;
    }
}

<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

/**
 * @internal
 */
enum FrontendEntry: string
{
    case STOCK = 'stock';
    case KEYSTONE = 'keystone';
    case OWN = 'own';
    case UNKNOWN = 'unknown';

    /**
     * Determine if the entry is the one Keystone sets up, so the installer wires its middleware and root view into the app.
     */
    public function isSetUpByKeystone(): bool
    {
        return $this === self::STOCK || $this === self::KEYSTONE;
    }
}

<?php

namespace ClaudioDekker\Keystone\Contracts;

use ClaudioDekker\Keystone\Devices\Device;

/**
 * @api
 */
interface SessionInfo
{
    /**
     * Get the platform and browser the user agent names, or null when either isn't known.
     *
     * It never throws, so a user agent it can't parse is a device it doesn't know.
     */
    public function device(string $userAgent): ?Device;
}

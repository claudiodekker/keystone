<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
interface SessionInfo
{
    /**
     * Describe the device the user agent names, or null when it can't be told; never throws.
     */
    public function describe(string $userAgent): ?Device;
}

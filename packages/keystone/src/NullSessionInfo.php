<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
class NullSessionInfo implements SessionInfo
{
    /**
     * Know nothing of the device the user agent names.
     */
    public function describe(string $userAgent): ?Device
    {
        return null;
    }
}

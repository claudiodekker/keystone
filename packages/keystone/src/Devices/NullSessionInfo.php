<?php

namespace ClaudioDekker\Keystone\Devices;

use ClaudioDekker\Keystone\Contracts\SessionInfo;

/**
 * @internal
 */
class NullSessionInfo implements SessionInfo
{
    /**
     * Get no device, as nothing parses the user agent.
     */
    public function device(string $userAgent): ?Device
    {
        return null;
    }
}

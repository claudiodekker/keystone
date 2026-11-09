<?php

namespace ClaudioDekker\Keystone\Locations;

use ClaudioDekker\Keystone\Contracts\IpLocation;

/**
 * @internal
 */
class NullIpLocation implements IpLocation
{
    /**
     * Get no location, as nothing looks the IP address up.
     */
    public function locate(string $ipAddress): ?string
    {
        return null;
    }
}

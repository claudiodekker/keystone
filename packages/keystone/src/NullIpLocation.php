<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
class NullIpLocation implements IpLocation
{
    /**
     * Know nothing of where the IP address is.
     */
    public function locate(string $ipAddress): ?string
    {
        return null;
    }
}

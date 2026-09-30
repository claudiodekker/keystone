<?php

namespace ClaudioDekker\Keystone;

/**
 * @api
 */
interface IpLocation
{
    /**
     * Name where the IP address is, or null when that isn't known; never throws.
     */
    public function locate(string $ipAddress): ?string;
}

<?php

namespace ClaudioDekker\Keystone\Contracts;

/**
 * @api
 */
interface IpLocation
{
    /**
     * Get where the IP address is, such as a city and its country, or null when it isn't known.
     *
     * It never throws, so a lookup that fails is a location it doesn't know.
     */
    public function locate(string $ipAddress): ?string;
}

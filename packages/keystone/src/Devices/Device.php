<?php

namespace ClaudioDekker\Keystone\Devices;

/**
 * @api
 */
readonly class Device
{
    /**
     * Create the device a user agent names, by its platform and browser.
     */
    public function __construct(
        public string $platform,
        public string $browser,
    ) {
        //
    }
}

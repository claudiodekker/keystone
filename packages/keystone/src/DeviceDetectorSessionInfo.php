<?php

namespace ClaudioDekker\Keystone;

use DeviceDetector\DeviceDetector;
use Throwable;

/**
 * @api
 */
class DeviceDetectorSessionInfo implements SessionInfo
{
    /**
     * Parse the platform and browser from the user agent with matomo/device-detector.
     */
    public function describe(string $userAgent): ?Device
    {
        try {
            $detector = new DeviceDetector($userAgent);

            $detector->parse();

            $platform = $this->known($detector->getOs('name'));
            $browser = $this->known($detector->getClient('name'));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $platform === null && $browser === null ? null : new Device(platform: $platform, browser: $browser);
    }

    /**
     * Keep a name the detector found, dropping its unknown marker.
     */
    protected function known(mixed $name): ?string
    {
        return is_string($name) && $name !== '' && $name !== DeviceDetector::UNKNOWN ? $name : null;
    }
}

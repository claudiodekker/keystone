<?php

namespace ClaudioDekker\Keystone\Devices;

use ClaudioDekker\Keystone\Contracts\SessionInfo;
use DeviceDetector\DeviceDetector;

/**
 * @internal
 */
class DeviceDetectorSessionInfo implements SessionInfo
{
    /**
     * Get the platform and browser matomo/device-detector parses from the user agent, or null when it can't name both.
     */
    public function device(string $userAgent): ?Device
    {
        return rescue(fn () => $this->parse($userAgent));
    }

    /**
     * Parse the user agent into the device it names.
     */
    protected function parse(string $userAgent): ?Device
    {
        $detector = new DeviceDetector($userAgent);

        $detector->parse();

        $platform = $detector->getOs('name');
        $browser = $detector->getClient('name');

        if (! $this->isKnown($platform) || ! $this->isKnown($browser)) {
            return null;
        }

        return new Device(platform: $platform, browser: $browser);
    }

    /**
     * Determine if the detector named the part.
     *
     * @param  array<array-key, string>|string|null  $name
     *
     * @phpstan-assert-if-true non-empty-string $name
     */
    protected function isKnown(array|string|null $name): bool
    {
        return is_string($name)
            && $name !== ''
            && $name !== DeviceDetector::UNKNOWN;
    }
}

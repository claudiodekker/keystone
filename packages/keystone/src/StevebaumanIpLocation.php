<?php

namespace ClaudioDekker\Keystone;

use Stevebauman\Location\Drivers\Driver;
use Stevebauman\Location\Drivers\HttpDriver;
use Stevebauman\Location\LocationRequest;
use Stevebauman\Location\Position;
use Throwable;

/**
 * @api
 */
class StevebaumanIpLocation implements IpLocation
{
    /**
     * Name the city and country of the IP address through stevebauman/location's drivers, skipping plaintext ones unless allowed.
     */
    public function locate(string $ipAddress): ?string
    {
        try {
            $driver = $this->driver($ipAddress);
            $request = LocationRequest::createFrom(request())->setIp($ipAddress);
            $position = $driver?->get($request);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $position instanceof Position ? $this->name($position) : null;
    }

    /**
     * Chain the configured driver and its fallbacks, leaving out every one the IP address may not be sent to.
     */
    protected function driver(string $ipAddress): ?Driver
    {
        $fallbacks = config('location.fallbacks');
        $classes = [config('location.driver'), ...(is_array($fallbacks) ? $fallbacks : [])];
        $drivers = array_map(fn (mixed $class) => is_string($class) && is_a($class, Driver::class, true) ? app($class) : null, $classes);
        $allowed = array_filter($drivers, fn (?Driver $driver) => $driver !== null && ! $this->isRefused($driver, $ipAddress));
        $first = array_shift($allowed);

        foreach ($allowed as $fallback) {
            $first?->fallback($fallback);
        }

        return $first;
    }

    /**
     * Determine if the driver would send the IP address in plaintext while the app doesn't allow it.
     */
    protected function isRefused(Driver $driver, string $ipAddress): bool
    {
        if (config('keystone.ip_location.allow_plaintext_driver') === true || ! $driver instanceof HttpDriver) {
            return false;
        }

        $url = strtolower($driver->url($ipAddress));

        return ! str_starts_with($url, 'https://');
    }

    /**
     * Name the position by its city and country, whichever are known.
     */
    protected function name(Position $position): ?string
    {
        $parts = array_filter([$position->cityName, $position->countryName]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}

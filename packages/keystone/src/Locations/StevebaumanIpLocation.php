<?php

namespace ClaudioDekker\Keystone\Locations;

use ClaudioDekker\Keystone\Contracts\IpLocation;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Stevebauman\Location\Drivers\Driver;
use Stevebauman\Location\Drivers\HttpDriver;
use Stevebauman\Location\LocationRequest;
use Stevebauman\Location\Position;

/**
 * @internal
 */
class StevebaumanIpLocation implements IpLocation
{
    /**
     * Create the IP location that asks the drivers stevebauman/location is configured with.
     */
    public function __construct(
        protected Config $config,
        protected Container $container,
    ) {
        //
    }

    /**
     * Get the city and country the first driver that knows the IP address names, skipping each driver that would send it over plain HTTP.
     */
    public function locate(string $ipAddress): ?string
    {
        return rescue(fn () => $this->lookUp($ipAddress));
    }

    /**
     * Ask each driver in turn until one knows the IP address.
     */
    protected function lookUp(string $ipAddress): ?string
    {
        $request = new LocationRequest;

        $request->setIp($ipAddress);

        foreach ($this->drivers() as $driver) {
            if (! $this->mayAsk($driver, $ipAddress)) {
                continue;
            }

            $position = $driver->get($request);

            if ($position instanceof Position) {
                return $this->label($position);
            }
        }

        return null;
    }

    /**
     * Get the configured driver, then each fallback, each on its own so none falls back to another.
     *
     * @return list<Driver>
     */
    protected function drivers(): array
    {
        $configured = [
            $this->config->get('location.driver'),
            ...(array) $this->config->get('location.fallbacks'),
        ];

        $classes = array_values(array_filter($configured, is_string(...)));

        return array_map(fn (string $class) => $this->container->make($class), $classes);
    }

    /**
     * Determine if the driver may be sent the IP address.
     */
    protected function mayAsk(Driver $driver, string $ipAddress): bool
    {
        if (! $driver instanceof HttpDriver) {
            return true;
        }

        return str_starts_with($driver->url($ipAddress), 'https://')
            || $this->config->get('keystone.ip_location.allow_plaintext_driver') === true;
    }

    /**
     * Get the city and country the position names, or null when it names neither.
     */
    protected function label(Position $position): ?string
    {
        $parts = array_filter([$position->cityName, $position->countryName]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}

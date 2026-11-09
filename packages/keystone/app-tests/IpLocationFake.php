<?php

namespace ClaudioDekker\Keystone\AppTests;

use ClaudioDekker\Keystone\Contracts\IpLocation;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
class IpLocationFake implements IpLocation
{
    /**
     * The location of each IP address the fake knows.
     *
     * @var array<string, string>
     */
    protected array $locations = [];

    /**
     * Every IP address looked up, in order.
     *
     * @var list<string>
     */
    protected array $located = [];

    /**
     * Have the IP address be at the location from here on.
     *
     * @return $this
     */
    public function knows(string $ipAddress, string $location): static
    {
        $this->locations[$ipAddress] = $location;

        return $this;
    }

    /**
     * Get the location the fake knows the IP address is at, or null.
     */
    public function locate(string $ipAddress): ?string
    {
        $this->located[] = $ipAddress;

        return $this->locations[$ipAddress] ?? null;
    }

    /**
     * Assert the IP address was looked up.
     */
    public function assertLocated(string $ipAddress): void
    {
        Assert::assertContains($ipAddress, $this->located, "The IP address [{$ipAddress}] was not looked up.");
    }

    /**
     * Assert no IP address was looked up.
     */
    public function assertNothingLocated(): void
    {
        Assert::assertSame([], $this->located, 'An IP address was looked up.');
    }
}

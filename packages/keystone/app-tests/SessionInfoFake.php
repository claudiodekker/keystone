<?php

namespace ClaudioDekker\Keystone\AppTests;

use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
class SessionInfoFake implements SessionInfo
{
    /**
     * The device each user agent the fake knows names.
     *
     * @var array<string, Device>
     */
    protected array $devices = [];

    /**
     * Every user agent parsed, in order.
     *
     * @var list<string>
     */
    protected array $parsed = [];

    /**
     * Have the user agent name the device from here on.
     *
     * @return $this
     */
    public function knows(string $userAgent, Device $device): static
    {
        $this->devices[$userAgent] = $device;

        return $this;
    }

    /**
     * Get the device the fake knows the user agent names, or null.
     */
    public function device(string $userAgent): ?Device
    {
        $this->parsed[] = $userAgent;

        return $this->devices[$userAgent] ?? null;
    }

    /**
     * Assert the user agent was parsed.
     */
    public function assertParsed(string $userAgent): void
    {
        Assert::assertContains($userAgent, $this->parsed, "The user agent [{$userAgent}] was not parsed.");
    }

    /**
     * Assert no user agent was parsed.
     */
    public function assertNothingParsed(): void
    {
        Assert::assertSame([], $this->parsed, 'A user agent was parsed.');
    }
}

<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Subnet
{
    /**
     * The bytes of an IPv4 address its /24 subnet keeps.
     */
    protected const int IPV4_NETWORK_BYTES = 3;

    /**
     * The length of a packed IPv4 address in bytes.
     */
    protected const int IPV4_BYTES = 4;

    /**
     * Create a new subnet instance from its CIDR, such as 203.0.113.0/24 or 2001:db8:0:1::/64.
     */
    public function __construct(
        public string $cidr,
    ) {
        //
    }

    /**
     * Get the subnet the IP address is on, or null when it can't be parsed: the /24 of an IPv4 address, IPv4-mapped IPv6 unwrapped first, else the /64.
     */
    public static function of(?string $ip): ?static
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        if (str_starts_with($packed, RateLimiter::IPV4_MAPPED_PREFIX)) {
            $packed = substr($packed, strlen(RateLimiter::IPV4_MAPPED_PREFIX));
        }

        if (strlen($packed) === self::IPV4_BYTES) {
            return new static(static::mask($packed, self::IPV4_NETWORK_BYTES).'/24');
        }

        return new static(static::mask($packed, RateLimiter::IPV6_NETWORK_BYTES).'/64');
    }

    /**
     * Determine if the other subnet is this one.
     */
    public function equals(?self $other): bool
    {
        return $other !== null && hash_equals($this->cidr, $other->cidr);
    }

    /**
     * Get the packed address as text with every byte after the ones its subnet keeps set to zero.
     */
    protected static function mask(string $packed, int $keptBytes): string
    {
        return (string) inet_ntop(substr($packed, 0, $keptBytes).str_repeat("\0", strlen($packed) - $keptBytes));
    }
}

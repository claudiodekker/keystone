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
     * The bytes of an IPv6 address its /64 network keeps.
     */
    protected const int IPV6_NETWORK_BYTES = 8;

    /**
     * The bytes an IPv4-mapped IPv6 address starts with.
     */
    protected const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

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
        $packed = static::pack($ip);

        if ($packed === null) {
            return null;
        }

        if (strlen($packed) === self::IPV4_BYTES) {
            return new static(static::mask($packed, self::IPV4_NETWORK_BYTES).'/24');
        }

        return new static(static::mask($packed, self::IPV6_NETWORK_BYTES).'/64');
    }

    /**
     * Get the network a request limit counts the IP address under, or null when it can't be parsed: an IPv4 address as is, IPv4-mapped IPv6 unwrapped first, else the /64 without its prefix length.
     */
    public static function networkOf(?string $ip): ?string
    {
        $packed = static::pack($ip);

        if ($packed === null) {
            return null;
        }

        return static::mask($packed, strlen($packed) === self::IPV4_BYTES ? self::IPV4_BYTES : self::IPV6_NETWORK_BYTES);
    }

    /**
     * Determine if the other subnet is this one.
     */
    public function equals(?self $other): bool
    {
        return $other !== null && hash_equals($this->cidr, $other->cidr);
    }

    /**
     * Get the packed IP address, IPv4-mapped IPv6 unwrapped to its IPv4 address, or null when it can't be parsed.
     */
    protected static function pack(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return substr($packed, strlen(self::IPV4_MAPPED_PREFIX));
        }

        return $packed;
    }

    /**
     * Get the packed address as text with every byte after the ones its subnet keeps set to zero.
     */
    protected static function mask(string $packed, int $keptBytes): string
    {
        return (string) inet_ntop(substr($packed, 0, $keptBytes).str_repeat("\0", strlen($packed) - $keptBytes));
    }
}

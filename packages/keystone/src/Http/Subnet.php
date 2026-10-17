<?php

namespace ClaudioDekker\Keystone\Http;

/**
 * The subnet an IP address sits in: the /24 for IPv4, the /64 for IPv6.
 *
 * @internal
 */
class Subnet
{
    /**
     * The packed prefix of an IPv4-mapped IPv6 address, which is treated as the IPv4 address it wraps.
     */
    protected const string MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * Get the subnet of the address in CIDR notation, or null when it is not an IP address.
     */
    public static function of(string $address): ?string
    {
        return static::mask($address, 24);
    }

    /**
     * Get the subnet a rate limit counts the address in: an IPv4 address on its own, an IPv6 address in its /64.
     */
    public static function counted(string $address): ?string
    {
        return static::mask($address, 32);
    }

    /**
     * Mask the address to the given IPv4 prefix length or the IPv6 /64, in CIDR notation, or null when it is not an IP address.
     */
    protected static function mask(string $address, int $ipv4Bits): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return null;
        }

        if (str_starts_with($packed, static::MAPPED_PREFIX)) {
            $packed = substr($packed, strlen(static::MAPPED_PREFIX));
        }

        $bits = strlen($packed) === 4 ? $ipv4Bits : 64;
        $kept = substr($packed, 0, intdiv($bits, 8));
        $masked = str_pad($kept, strlen($packed), "\0");

        return inet_ntop($masked).'/'.$bits;
    }
}

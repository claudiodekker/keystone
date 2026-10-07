<?php

use ClaudioDekker\Keystone\Subnet;

it('never changes how an address maps to its subnet', function (?string $ip, ?string $cidr) {
    expect(Subnet::of($ip)?->cidr)->toBe($cidr);
})->with([
    'an IPv4 address' => ['203.0.113.77', '203.0.113.0/24'],
    'the first address of a /24' => ['203.0.113.0', '203.0.113.0/24'],
    'the last address of a /24' => ['203.0.113.255', '203.0.113.0/24'],
    'the next /24' => ['203.0.114.1', '203.0.114.0/24'],
    'an IPv4-mapped IPv6 address' => ['::ffff:203.0.113.77', '203.0.113.0/24'],
    'an IPv4-mapped IPv6 address in hexadecimal' => ['::ffff:cb00:714d', '203.0.113.0/24'],
    'an IPv6 address' => ['2001:db8:0:1:aaaa:bbbb:cccc:dddd', '2001:db8:0:1::/64'],
    'the first address of a /64' => ['2001:db8:0:1::', '2001:db8:0:1::/64'],
    'the last address of a /64' => ['2001:db8:0:1:ffff:ffff:ffff:ffff', '2001:db8:0:1::/64'],
    'the next /64' => ['2001:db8:0:2::1', '2001:db8:0:2::/64'],
    'an uncompressed IPv6 address' => ['2001:0db8:0000:0001:0000:0000:0000:0001', '2001:db8:0:1::/64'],
    'the IPv6 loopback' => ['::1', '::/64'],
    'no address' => [null, null],
    'an empty address' => ['', null],
    'a hostname' => ['not-an-ip', null],
    'an IPv4 address with a port' => ['203.0.113.77:443', null],
    'an IPv4 address out of range' => ['203.0.113.256', null],
    'an IPv6 address in brackets' => ['[2001:db8::1]', null],
    'an IPv6 address with a zone' => ['fe80::1%eth0', null],
    'an address with a prefix length' => ['203.0.113.0/24', null],
]);

it('never calls two subnets equal unless they are the same one', function (string $ip, ?string $other, bool $equal) {
    expect(Subnet::of($ip)->equals(Subnet::of($other)))->toBe($equal);
})->with([
    'two addresses in one /24' => ['203.0.113.1', '203.0.113.254', true],
    'an IPv4 address and its IPv4-mapped form' => ['203.0.113.1', '::ffff:203.0.113.254', true],
    'two addresses in one /64' => ['2001:db8:0:1::1', '2001:db8:0:1:ffff::2', true],
    'two /24s' => ['203.0.113.1', '203.0.114.1', false],
    'two /64s' => ['2001:db8:0:1::1', '2001:db8:0:2::1', false],
    'an IPv4 and an IPv6 address' => ['203.0.113.1', '2001:db8:0:1::1', false],
    'an address that can\'t be parsed' => ['203.0.113.1', 'not-an-ip', false],
    'no address' => ['203.0.113.1', null, false],
]);

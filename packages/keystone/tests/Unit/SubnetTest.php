<?php

use ClaudioDekker\Keystone\Http\Subnet;

it('puts an IPv4 address in its /24', function () {
    expect(Subnet::of('203.0.113.97'))->toBe('203.0.113.0/24');
});

it('puts an IPv6 address in its /64', function () {
    expect(Subnet::of('2001:db8:85a3:8d3:1319:8a2e:370:7348'))->toBe('2001:db8:85a3:8d3::/64');
});

it('unwraps an IPv4-mapped IPv6 address and puts it in its /24', function () {
    expect(Subnet::of('::ffff:203.0.113.97'))->toBe('203.0.113.0/24');
});

it('has no subnet for something that is not an IP address', function (string $address) {
    expect(Subnet::of($address))->toBeNull();
})->with([
    'a host name' => 'example.com',
    'an address with a port' => '203.0.113.97:443',
    'an empty string' => '',
]);

it('counts an IPv4 address on its own', function () {
    expect(Subnet::counted('203.0.113.97'))->toBe('203.0.113.97/32');
});

it('counts an IPv6 address in its /64', function () {
    expect(Subnet::counted('2001:db8:85a3:8d3:1319:8a2e:370:7348'))->toBe('2001:db8:85a3:8d3::/64');
});

it('unwraps an IPv4-mapped IPv6 address and counts it as the IPv4 address', function () {
    expect(Subnet::counted('::ffff:203.0.113.97'))->toBe('203.0.113.97/32');
});

it('counts nothing that is not an IP address', function (string $address) {
    expect(Subnet::counted($address))->toBeNull();
})->with([
    'a host name' => 'example.com',
    'an address with a port' => '203.0.113.97:443',
    'an empty string' => '',
]);

<?php

use ClaudioDekker\Keystone\AppTests\IpLocationFake;
use PHPUnit\Framework\AssertionFailedError;

it('passes assertLocated once the address was looked up', function () {
    $fake = new IpLocationFake;

    $fake->locate('203.0.113.9');

    $fake->assertLocated('203.0.113.9');
});

it('fails assertLocated when another address was looked up', function () {
    $fake = new IpLocationFake;

    $fake->locate('198.51.100.4');

    expect(fn () => $fake->assertLocated('203.0.113.9'))->toThrow(AssertionFailedError::class, 'The IP address [203.0.113.9] was not looked up.');
});

it('passes assertNothingLocated when no address was looked up', function () {
    $fake = new IpLocationFake;

    $fake->assertNothingLocated();
});

it('fails assertNothingLocated once an address was looked up', function () {
    $fake = new IpLocationFake;

    $fake->locate('203.0.113.9');

    expect(fn () => $fake->assertNothingLocated())->toThrow(AssertionFailedError::class, 'An IP address was looked up.');
});

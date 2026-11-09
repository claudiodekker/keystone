<?php

use ClaudioDekker\Keystone\AppTests\IpLocationFake;
use ClaudioDekker\Keystone\Contracts\IpLocation;
use ClaudioDekker\Keystone\Locations\StevebaumanIpLocation;
use Illuminate\Support\Facades\Http;
use Stevebauman\Location\Drivers\IpApi;

$contract = function (Closure $makeIpLocation) {
    it('names where a public IP address is', function () use ($makeIpLocation) {
        $ipLocation = $makeIpLocation();

        $location = $ipLocation->locate('8.8.8.8');

        expect($location)->toContain('United States');
    });

    it('knows no location for an address no lookup can place', function (string $ipAddress) use ($makeIpLocation) {
        $ipLocation = $makeIpLocation();

        $location = $ipLocation->locate($ipAddress);

        expect($location)->toBeNull();
    })->with([
        'a private address' => '10.0.0.1',
        'something that is no address' => 'not an address',
    ]);
};

describe('the fake', fn () => $contract(function (): IpLocation {
    $fake = new IpLocationFake;

    return $fake->knows('8.8.8.8', 'Ashburn, United States');
}));

describe('stevebauman/location', fn () => $contract(function (): IpLocation {
    Http::allowStrayRequests();
    config([
        'keystone.ip_location.allow_plaintext_driver' => true,
        'location.driver' => IpApi::class,
        'location.fallbacks' => [],
    ]);

    return app(StevebaumanIpLocation::class);
}))->group('ip-location');

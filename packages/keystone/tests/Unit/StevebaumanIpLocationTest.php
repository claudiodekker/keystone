<?php

use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\NullIpLocation;
use ClaudioDekker\Keystone\StevebaumanIpLocation;
use ClaudioDekker\Keystone\Tests\Fixtures\ThrowingLocationDriver;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Stevebauman\Location\Drivers\IpApi;
use Stevebauman\Location\Drivers\IpApiPro;
use Stevebauman\Location\Drivers\IpInfo;

const IP_API_FIELDS = 'fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,currency,isp,org,as,query';

function ipApiPro(array $body = ['status' => 'success', 'country' => 'Netherlands', 'regionName' => 'North Holland', 'city' => 'Amsterdam']): void
{
    Http::fake(['https://pro.ip-api.com/json/203.0.113.7?key=secret&'.IP_API_FIELDS => Http::response($body)]);
}

function ipApi(): void
{
    Http::fake(['http://ip-api.com/json/203.0.113.7?'.IP_API_FIELDS => Http::response(['country' => 'Netherlands', 'city' => 'Amsterdam'])]);
}

beforeEach(function () {
    config(['location.ip_api.token' => 'secret']);
});

it('names the city and country of the IP address', function () {
    config(['location.driver' => IpApiPro::class, 'location.fallbacks' => []]);
    ipApiPro();

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBe('Amsterdam, Netherlands');
});

it('refuses a driver that sends the IP address in plaintext, falling back to the next', function () {
    config(['location.driver' => IpApi::class, 'location.fallbacks' => [IpInfo::class, IpApiPro::class]]);
    ipApi();
    ipApiPro();

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBe('Amsterdam, Netherlands');
    Http::assertSentCount(1);
});

it('uses a plaintext driver once the app allows it', function () {
    config(['location.driver' => IpApi::class, 'location.fallbacks' => [], 'keystone.ip_location.allow_plaintext_driver' => true]);
    ipApi();

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBe('Amsterdam, Netherlands');
});

it('knows nothing when every driver is plaintext', function () {
    config(['location.driver' => IpApi::class, 'location.fallbacks' => [IpInfo::class]]);
    ipApi();

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBeNull();
    Http::assertNothingSent();
});

it('knows nothing of an IP address no driver can place', function () {
    config(['location.driver' => IpApiPro::class, 'location.fallbacks' => []]);
    ipApiPro(['status' => 'fail']);

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBeNull();
});

it('knows nothing, and throws nothing, when a driver throws', function () {
    config(['location.driver' => ThrowingLocationDriver::class, 'location.fallbacks' => []]);

    $location = (new StevebaumanIpLocation)->locate('203.0.113.7');

    expect($location)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Driver broke.');
});

it('is the IP-location port while stevebauman/location is installed', function () {
    expect(app(IpLocation::class))->toBeInstanceOf(StevebaumanIpLocation::class);
});

test('the null IP-location port knows nothing', function () {
    $location = (new NullIpLocation)->locate('203.0.113.7');

    expect($location)->toBeNull();
});

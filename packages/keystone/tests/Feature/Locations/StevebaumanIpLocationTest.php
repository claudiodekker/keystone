<?php

use ClaudioDekker\Keystone\Locations\StevebaumanIpLocation;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Stevebauman\Location\Drivers\IpApi;
use Stevebauman\Location\Drivers\IpApiPro;

beforeEach(function () {
    config(['location.ip_api.token' => 'secret-7731']);

    Http::fake([
        'http://ip-api.com/*' => Http::response(['status' => 'success', 'city' => 'Ashburn', 'country' => 'United States']),
        'https://pro.ip-api.com/*' => Http::response(['status' => 'success', 'city' => 'Reykjavík', 'country' => 'Iceland']),
    ]);
});

it('sends the IP address to no driver whose URL is plain HTTP', function () {
    config(['location.driver' => IpApi::class, 'location.fallbacks' => []]);

    $location = app(StevebaumanIpLocation::class)->locate('8.8.8.8');

    expect($location)->toBeNull();
    Http::assertNothingSent();
});

it('asks the next driver whose URL is https', function () {
    config(['location.driver' => IpApi::class, 'location.fallbacks' => [IpApiPro::class]]);

    $location = app(StevebaumanIpLocation::class)->locate('8.8.8.8');

    expect($location)->toBe('Reykjavík, Iceland');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://pro.ip-api.com/json/8.8.8.8'));
});

it('asks a driver whose URL is plain HTTP once the app allows it', function () {
    config(['keystone.ip_location.allow_plaintext_driver' => true, 'location.driver' => IpApi::class, 'location.fallbacks' => []]);

    $location = app(StevebaumanIpLocation::class)->locate('8.8.8.8');

    expect($location)->toBe('Ashburn, United States');
});

it('reports a driver that fails, and knows no location', function () {
    config(['location.driver' => 'App\Location\MissingDriver', 'location.fallbacks' => []]);

    $location = app(StevebaumanIpLocation::class)->locate('8.8.8.8');

    expect($location)->toBeNull();
    Exceptions::assertReported(fn (BindingResolutionException $exception) => str_contains($exception->getMessage(), 'App\Location\MissingDriver'));
});

it('knows no location, and reports nothing, when the config names no driver', function () {
    config(['location.driver' => null, 'location.fallbacks' => null]);

    $location = app(StevebaumanIpLocation::class)->locate('8.8.8.8');

    expect($location)->toBeNull();
    Http::assertNothingSent();
});

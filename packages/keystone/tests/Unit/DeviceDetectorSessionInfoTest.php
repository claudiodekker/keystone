<?php

use ClaudioDekker\Keystone\Device;
use ClaudioDekker\Keystone\DeviceDetectorSessionInfo;
use ClaudioDekker\Keystone\NullSessionInfo;
use ClaudioDekker\Keystone\SessionInfo;

it('parses the platform and browser from the user agent', function (string $userAgent, ?string $platform, ?string $browser) {
    $device = (new DeviceDetectorSessionInfo)->describe($userAgent);

    expect($device)->toEqual(new Device(platform: $platform, browser: $browser));
})->with([
    'Firefox on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Windows', 'Firefox'],
    'Safari on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'iOS', 'Mobile Safari'],
    'a client on an unknown platform' => ['curl/8.0.1', null, 'curl'],
]);

it('knows nothing of a user agent it can\'t parse', function (string $userAgent) {
    $device = (new DeviceDetectorSessionInfo)->describe($userAgent);

    expect($device)->toBeNull();
})->with(['gibberish' => ['definitely not a browser'], 'empty' => ['']]);

it('is the session-info port while device-detector is installed', function () {
    expect(app(SessionInfo::class))->toBeInstanceOf(DeviceDetectorSessionInfo::class);
});

test('the null session-info port knows nothing', function () {
    $device = (new NullSessionInfo)->describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0');

    expect($device)->toBeNull();
});

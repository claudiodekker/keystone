<?php

use ClaudioDekker\Keystone\AppTests\SessionInfoFake;
use ClaudioDekker\Keystone\Contracts\SessionInfo;
use ClaudioDekker\Keystone\Devices\Device;
use ClaudioDekker\Keystone\Devices\DeviceDetectorSessionInfo;

dataset('session info', [
    'matomo/device-detector' => [fn () => new DeviceDetectorSessionInfo],
    'the fake' => [function () {
        $fake = new SessionInfoFake;

        return $fake->knows('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', new Device(platform: 'Mac', browser: 'Chrome'));
    }],
]);

it('names the platform and the browser of a user agent it knows', function (SessionInfo $sessionInfo) {
    $device = $sessionInfo->device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');

    expect($device)->toEqual(new Device(platform: 'Mac', browser: 'Chrome'));
})->with('session info');

it('knows no device for a user agent that names neither a platform nor a browser', function (SessionInfo $sessionInfo, string $userAgent) {
    $device = $sessionInfo->device($userAgent);

    expect($device)->toBeNull();
})->with('session info')->with([
    'an empty one' => '',
    'made-up text' => 'nothing to see here',
    'binary bytes' => "\xff\xfe\x00\x81",
]);

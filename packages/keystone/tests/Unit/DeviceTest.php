<?php

use ClaudioDekker\Keystone\Device;

test('a device with a known browser and platform is labelled by both', function () {
    $device = new Device(platform: 'Windows', browser: 'Firefox');

    expect($device->label())->toBe(__('keystone::alerts.device', ['browser' => 'Firefox', 'platform' => 'Windows']));
});

test('a device is labelled by whichever of its browser and platform is known', function (?string $platform, ?string $browser, ?string $label) {
    $device = new Device(platform: $platform, browser: $browser);

    expect($device->label())->toBe($label);
})->with([
    'only the platform' => ['Windows', null, 'Windows'],
    'only the browser' => [null, 'Firefox', 'Firefox'],
    'neither' => [null, null, null],
]);

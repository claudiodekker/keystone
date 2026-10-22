<?php

use ClaudioDekker\Keystone\KeystoneServiceProvider;

it("keeps core's default for a nested key the app's file lacks", function () {
    config(['keystone' => ['rate_limits' => ['requests_per_minute' => ['view' => 5]]]]);

    (new KeystoneServiceProvider($this->app))->register();

    expect(config('keystone.rate_limits'))->toBe([
        'failed_attempts_per_hour' => 20,
        'requests_per_minute' => ['change' => 10, 'submit' => 10, 'view' => 5],
    ])->and(config('keystone.events.enabled'))->toBeTrue();
});

it("replaces core's list whole with the app's, whatever its length", function (array $list) {
    config(['keystone' => ['clear_site_data' => $list]]);

    (new KeystoneServiceProvider($this->app))->register();

    expect(config('keystone.clear_site_data'))->toBe($list);
})->with([
    'a shorter list' => [['cookies']],
    'an empty list' => [[]],
    'a longer list' => [['cache', 'cookies', 'storage', 'executionContexts']],
]);

it("keeps core's map when the app's file holds an empty array for it", function () {
    config(['keystone' => ['hardening' => [], 'rate_limits' => ['requests_per_minute' => []]]]);

    (new KeystoneServiceProvider($this->app))->register();

    expect(config('keystone.hardening'))->toBe(['frame_ancestors' => []])
        ->and(config('keystone.rate_limits.requests_per_minute'))->toBe(['change' => 10, 'submit' => 10, 'view' => 60]);
});

it('replaces a scalar and merges a map that holds a list in one pass', function () {
    config(['keystone' => ['hardening' => ['frame_ancestors' => ['https://portal.example.com']], 'log_channel' => 'stderr']]);

    (new KeystoneServiceProvider($this->app))->register();

    expect(config('keystone.hardening'))->toBe(['frame_ancestors' => ['https://portal.example.com']])
        ->and(config('keystone.log_channel'))->toBe('stderr')
        ->and(config('keystone.clear_site_data'))->toBe(['cache', 'storage']);
});

it('uses a cached config as cached', function () {
    $this->app->instance('config_loaded_from_cache', true);
    config(['keystone' => ['events' => ['enabled' => false]]]);

    (new KeystoneServiceProvider($this->app))->register();

    expect(config('keystone'))->toBe(['events' => ['enabled' => false]]);
});

<?php

use ClaudioDekker\Keystone\KeystoneServiceProvider;

describe('merging the app\'s config', function () {
    it('merges the app\'s maps over core\'s, keeping core\'s default for every key the app leaves out', function () {
        app()->instance('config_loaded_from_cache', false);
        config(['keystone' => [
            'rate_limits' => ['requests_per_minute' => ['view' => 120]],
            'hardening' => [],
            'log_channel' => 'stack',
        ]]);

        (new KeystoneServiceProvider(app()))->register();

        expect(config('keystone.rate_limits'))->toBe([
            'requests_per_minute' => ['view' => 120, 'start' => 10, 'submit' => 10, 'change' => 10],
            'failed_attempts_per_hour' => 20,
            'deliveries_per_ten_minutes' => 3,
        ])
            ->and(config('keystone.hardening'))->toBe(['frame_ancestors' => []])
            ->and(config('keystone.log_channel'))->toBe('stack')
            ->and(config('keystone.events.enabled'))->toBeTrue();
    });

    it('replaces core\'s lists with the app\'s whole', function (string $key, array $list) {
        app()->instance('config_loaded_from_cache', false);
        config(['keystone' => []]);
        config(["keystone.{$key}" => $list]);

        (new KeystoneServiceProvider(app()))->register();

        expect(config("keystone.{$key}"))->toBe($list);
    })->with([
        'a shorter list' => ['clear_site_data', ['cookies']],
        'an empty list' => ['clear_site_data', []],
        'a list over an empty default' => ['hardening.frame_ancestors', ["'self'"]],
    ]);

    it('leaves a cached config as it was cached', function () {
        app()->instance('config_loaded_from_cache', true);
        config(['keystone' => ['log_channel' => 'stack']]);

        (new KeystoneServiceProvider(app()))->register();

        expect(config('keystone'))->toBe(['log_channel' => 'stack']);
    });
});

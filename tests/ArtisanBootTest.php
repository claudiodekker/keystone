<?php

use ClaudioDekker\Keystone\Tests\ArtisanApp;
use Illuminate\Support\Str;

it('fails an artisan command with one exception naming every problem in the config', function () {
    ArtisanApp::using([
        'rate_limits' => ['failed_attempts_per_hour' => 0],
        'events' => ['enabled' => 'yes'],
    ], function (ArtisanApp $app) {
        $result = $app->artisan('about');

        expect($result->exitCode())->toBe(1)
            ->and(Str::substrCount($result->output(), "Keystone refused to boot:\n"))->toBe(1)
            ->and($result->output())->toContain('- keystone.rate_limits.failed_attempts_per_hour must be a positive integer.')
            ->and($result->output())->toContain('- keystone.events.enabled must be true or false.');
    });
});

it('runs an artisan command in an app whose config holds no problem', function () {
    ArtisanApp::using([], function (ArtisanApp $app) {
        expect($app->artisan('about')->successful())->toBeTrue();
    });
});

it('caches a config that holds no problem', function (string $command) {
    ArtisanApp::using([], function (ArtisanApp $app) use ($command) {
        $result = $app->artisan($command);

        expect($result->successful())->toBeTrue()
            ->and($app->hasCachedConfig())->toBeTrue();
    });
})->with(['config:cache', 'optimize']);

it('refuses to cache a config that holds a problem', function (string $command) {
    ArtisanApp::using(['events' => ['enabled' => 'yes']], function (ArtisanApp $app) use ($command) {
        $result = $app->artisan($command);

        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toContain('- keystone.events.enabled must be true or false.')
            ->and($app->hasCachedConfig())->toBeFalse();
    });
})->with(['config:cache', 'optimize']);

it('runs the commands that clear the cached config in an app whose config holds a problem', function (string $command) {
    ArtisanApp::using(['events' => ['enabled' => 'yes']], function (ArtisanApp $app) use ($command) {
        expect($app->artisan($command)->successful())->toBeTrue();
    });
})->with(['config:clear', 'optimize:clear']);

it('boots an app without checking a cached config', function () {
    ArtisanApp::using([], function (ArtisanApp $app) {
        $app->cacheConfigEditedBy(['events' => ['enabled' => 'yes']]);

        expect($app->artisan('about')->successful())->toBeTrue();
    });
});

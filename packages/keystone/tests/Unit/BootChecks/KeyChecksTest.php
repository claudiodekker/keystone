<?php

it("boots with core's defaults", function () {
    expect($this->bootFailures())->toBe([]);
});

it('refuses a failed-attempt allowance that is not a positive integer', function (mixed $value) {
    config(['keystone.rate_limits.failed_attempts_per_hour' => $value]);

    expect($this->bootFailures())->toBe(['keystone.rate_limits.failed_attempts_per_hour must be a positive integer.']);
})->with([
    'zero' => 0,
    'negative' => -1,
    'null' => null,
    'a numeric string' => '20',
    'a float' => 20.5,
    'true' => true,
    'a list' => [[20]],
]);

it('refuses a request allowance that is not a positive integer', function (string $kind, mixed $value) {
    config(["keystone.rate_limits.requests_per_minute.{$kind}" => $value]);

    expect($this->bootFailures())->toBe(["keystone.rate_limits.requests_per_minute.{$kind} must be a positive integer."]);
})->with(['change', 'submit', 'view'])->with([
    'zero' => 0,
    'negative' => -1,
    'null' => null,
    'a numeric string' => '10',
    'a float' => 10.5,
    'true' => true,
    'a list' => [[10]],
]);

it('refuses request allowances that are gone', function () {
    config(['keystone.rate_limits.requests_per_minute' => 10]);

    expect($this->bootFailures())->toBe([
        'keystone.rate_limits.requests_per_minute.change must be a positive integer.',
        'keystone.rate_limits.requests_per_minute.submit must be a positive integer.',
        'keystone.rate_limits.requests_per_minute.view must be a positive integer.',
    ]);
});

it('boots with the smallest allowances', function () {
    config([
        'keystone.rate_limits.failed_attempts_per_hour' => 1,
        'keystone.rate_limits.requests_per_minute' => ['change' => 1, 'submit' => 1, 'view' => 1],
    ]);

    expect($this->bootFailures())->toBe([]);
});

it('refuses events.enabled when it is not a boolean', function (mixed $value) {
    config(['keystone.events.enabled' => $value]);

    expect($this->bootFailures())->toBe(['keystone.events.enabled must be true or false.']);
})->with([
    'a string' => 'yes',
    'one' => 1,
    'zero' => 0,
    'null' => null,
    'a list' => [[]],
]);

it('boots with the recorder switched off', function () {
    config(['keystone.events.enabled' => false]);

    expect($this->bootFailures())->toBe([]);
});

it('refuses a log channel that the logging config does not hold', function (mixed $value) {
    config(['keystone.log_channel' => $value]);

    expect($this->bootFailures())->toBe(['keystone.log_channel must be null or the name of a channel in the logging config.']);
})->with([
    'an empty name' => '',
    'a channel the app lacks' => 'audit',
    'an integer' => 5,
    'false' => false,
    'a list' => [['stack']],
]);

it('boots with the app default channel or a channel the app holds', function (?string $channel) {
    config(['keystone.log_channel' => $channel]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'the default channel' => null,
    'a stack' => 'stack',
    'a stream' => 'stderr',
]);

it('refuses frame ancestors that are not single CSP sources', function (mixed $value) {
    config(['keystone.hardening.frame_ancestors' => $value]);

    expect($this->bootFailures())->toBe(['keystone.hardening.frame_ancestors must be a list of CSP sources, each a single expression without spaces, semicolons or commas.']);
})->with([
    'a string' => "'self'",
    'null' => null,
    'a map' => [['portal' => 'https://portal.example.com']],
    'two sources in one' => [["'self' https://portal.example.com"]],
    'a directive' => [['https://portal.example.com; script-src *']],
    'a comma' => [['https://a.example.com,https://b.example.com']],
    'a non-string' => [[1]],
    'an empty string' => [['']],
]);

it('boots with frame ancestors that are single CSP sources', function (array $sources) {
    config(['keystone.hardening.frame_ancestors' => $sources]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'none' => [[]],
    'a keyword and a host' => [["'self'", 'https://portal.example.com']],
]);

it('refuses trusted origins that are not serialized origins', function (mixed $value) {
    config(['keystone.trusted_origins' => $value]);

    expect($this->bootFailures())->toBe(['keystone.trusted_origins must be a list of origins, each a scheme and a host with nothing after it, such as https://admin.example.com.']);
})->with([
    'a string' => 'https://admin.example.com',
    'null' => null,
    'a map' => [['admin' => 'https://admin.example.com']],
    'a path' => [['https://admin.example.com/login']],
    'a query' => [['https://admin.example.com?next=1']],
    'a userinfo' => [['https://user@admin.example.com']],
    'no scheme' => [['admin.example.com']],
    'another scheme' => [['ftp://admin.example.com']],
    'a non-string' => [[null]],
]);

it('boots with trusted origins that are serialized origins', function (array $origins) {
    config(['keystone.trusted_origins' => $origins]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'none' => [[]],
    'a host and a port' => [['https://admin.example.com', 'http://localhost:8000']],
]);

it('refuses clear_site_data that is not a list of kinds', function (mixed $value) {
    config(['keystone.clear_site_data' => $value]);

    expect($this->bootFailures())->toBe(['keystone.clear_site_data must be a list of cache, cookies, executionContexts and storage.']);
})->with([
    'a string' => 'cache',
    'null' => null,
    'a map' => [['kind' => 'cache']],
    'a kind that does not exist' => [['cache', 'everything']],
    'a non-string' => [[1]],
    'the header value' => [['"cache"']],
]);

it('boots with clear_site_data that is empty or lists kinds', function (array $kinds) {
    config(['keystone.clear_site_data' => $kinds]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'none' => [[]],
    'one kind' => [['cookies']],
    'every kind' => [['cache', 'cookies', 'executionContexts', 'storage']],
]);

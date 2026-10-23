<?php

beforeEach(function () {
    $this->app['env'] = 'production';

    config([
        'app.url' => 'https://keystone.example.com',
        'cache.default' => 'database',
        'cache.limiter' => null,
        'mail.default' => 'smtp',
        'queue.default' => 'database',
        'session.cookie' => '__Host-keystone-session',
        'session.domain' => null,
        'session.http_only' => true,
        'session.path' => '/',
        'session.same_site' => 'lax',
        'session.secure' => true,
    ]);
});

dataset('settings production refuses', [
    'a default guard on the session driver' => [
        ['auth.guards.web.driver' => 'session'],
        "The default auth guard [web] uses the [session] driver, but Keystone's routes need the keystone driver on it.",
    ],
    'a default guard with no driver' => [
        ['auth.guards.web.driver' => null],
        "The default auth guard [web] has no driver, but Keystone's routes need the keystone driver on it.",
    ],
    'no type offered on sign-in' => [
        ['keystone.methods' => ['challenge-only']],
        'No credential type is offered on sign-in, so no one could sign in. Install a method package and leave it in keystone.methods.',
    ],
    'a session cookie that is not secure' => [
        ['session.secure' => false],
        'session.secure must be true in production, so the browser sends the session cookie over HTTPS only.',
    ],
    'a session cookie that is not set secure at all' => [
        ['session.secure' => null],
        'session.secure must be true in production, so the browser sends the session cookie over HTTPS only.',
    ],
    'a session cookie that scripts can read' => [
        ['session.http_only' => false],
        "session.http_only must be true in production, so scripts can't read the session cookie.",
    ],
    'a session cookie that cross-site requests carry' => [
        ['session.same_site' => 'none'],
        'session.same_site must be lax or strict in production, so the browser keeps the session cookie off cross-site requests.',
    ],
    'a session cookie with no same-site rule' => [
        ['session.same_site' => null],
        'session.same_site must be lax or strict in production, so the browser keeps the session cookie off cross-site requests.',
    ],
    'a session cookie without a prefix' => [
        ['session.cookie' => 'keystone-session'],
        'session.cookie must start with __Host- in production, or with __Secure- when session.domain is set, so no other subdomain can set the session cookie.',
    ],
    'a session cookie with the secure prefix and no domain' => [
        ['session.cookie' => '__Secure-keystone-session'],
        'session.cookie must start with __Host- in production, or with __Secure- when session.domain is set, so no other subdomain can set the session cookie.',
    ],
    'a session cookie with the host prefix and a domain' => [
        ['session.domain' => 'example.com'],
        'session.cookie must start with __Host- in production, or with __Secure- when session.domain is set, so no other subdomain can set the session cookie.',
    ],
    'a session cookie on a path' => [
        ['session.path' => '/app'],
        'session.path must be / in production, since a prefixed cookie is only set for the whole site.',
    ],
    'an array cache' => [
        ['cache.default' => 'array', 'cache.limiter' => 'database'],
        'The default cache store [array] uses the [array] driver, which keeps nothing between requests. Set cache.default to a persistent store.',
    ],
    'a null cache' => [
        ['cache.default' => 'null', 'cache.limiter' => 'database'],
        'The default cache store [null] uses the [null] driver, which keeps nothing between requests. Set cache.default to a persistent store.',
    ],
    'a file limiter store' => [
        ['cache.limiter' => 'file'],
        "The rate limiter's cache store [file] uses the [file] driver, which can't count atomically. Set cache.limiter to a store that can.",
    ],
    'a file default store the limiter falls back to' => [
        ['cache.default' => 'file'],
        "The rate limiter's cache store [file] uses the [file] driver, which can't count atomically. Set cache.limiter to a store that can.",
    ],
    'an array limiter store' => [
        ['cache.limiter' => 'array'],
        "The rate limiter's cache store [array] uses the [array] driver, which can't count atomically. Set cache.limiter to a store that can.",
    ],
    'a null limiter store' => [
        ['cache.limiter' => 'null'],
        "The rate limiter's cache store [null] uses the [null] driver, which can't count atomically. Set cache.limiter to a store that can.",
    ],
    'an app URL on http' => [
        ['app.url' => 'http://keystone.example.com'],
        'app.url must be an https URL in production, since Keystone builds its links from it.',
    ],
    'an app URL with no scheme' => [
        ['app.url' => 'keystone.example.com'],
        'app.url must be an https URL in production, since Keystone builds its links from it.',
    ],
    'a log mailer' => [
        ['mail.default' => 'log'],
        'The default mailer [log] uses the [log] transport, which delivers nothing. Set mail.default to a mailer that delivers.',
    ],
    'an array mailer' => [
        ['mail.default' => 'array'],
        'The default mailer [array] uses the [array] transport, which delivers nothing. Set mail.default to a mailer that delivers.',
    ],
    'a mailer named for a transport that delivers nothing' => [
        ['mail.default' => 'outbox', 'mail.mailers.outbox' => ['transport' => 'log']],
        'The default mailer [outbox] uses the [log] transport, which delivers nothing. Set mail.default to a mailer that delivers.',
    ],
    'a null queue' => [
        ['queue.default' => 'null'],
        'The default queue connection [null] uses the [null] driver, which drops every job. Set queue.default to a connection that runs them.',
    ],
    'a queue connection on the null driver' => [
        ['queue.default' => 'void', 'queue.connections.void' => ['driver' => 'null']],
        'The default queue connection [void] uses the [null] driver, which drops every job. Set queue.default to a connection that runs them.',
    ],
]);

it('boots in production with a clean setup', function () {
    expect($this->bootFailures())->toBe([]);
});

it('refuses a setup in production that it boots elsewhere', function (array $settings, string $failure) {
    config($settings);

    expect($this->bootFailures())->toBe([$failure]);
})->with('settings production refuses');

it('boots the same setup in every other environment', function (array $settings, string $failure, string $environment) {
    $this->app['env'] = $environment;
    config($settings);

    expect($this->bootFailures())->toBe([]);
})->with('settings production refuses')->with(['local', 'staging', 'testing']);

it('boots in production with a secure prefix, a domain and any path the prefix allows', function () {
    config(['session.cookie' => '__Secure-keystone-session', 'session.domain' => 'example.com']);

    expect($this->bootFailures())->toBe([]);
});

it('boots in production with a strict same-site rule and an upper case one', function (string $sameSite) {
    config(['session.same_site' => $sameSite]);

    expect($this->bootFailures())->toBe([]);
})->with(['strict', 'Lax']);

it('boots in production with a queue that runs jobs on the sync driver', function () {
    config(['queue.default' => 'sync']);

    expect($this->bootFailures())->toBe([]);
});

it('lists every production failure with the failures of the other checks', function () {
    config(['session.secure' => false, 'keystone.events.enabled' => 'yes']);

    expect($this->bootFailures())->toBe([
        'keystone.events.enabled must be true or false.',
        'session.secure must be true in production, so the browser sends the session cookie over HTTPS only.',
    ]);
});

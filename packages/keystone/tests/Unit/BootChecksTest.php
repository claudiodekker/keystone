<?php

use ClaudioDekker\Keystone\BootChecks;
use ClaudioDekker\Keystone\Exceptions\Misconfigured;
use ClaudioDekker\Keystone\KeystoneServiceProvider;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Tests\Fixtures\FlakyAlert;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\UserOnOtherConnection;

function bootFailures(): array
{
    try {
        (new BootChecks(app(CredentialTypes::class)))->check();
    } catch (Misconfigured $e) {
        return $e->failures;
    }

    return [];
}

it('boots with core\'s defaults', function () {
    $failures = bootFailures();

    expect($failures)->toBe([]);
});

it('refuses a misconfigured app when Keystone boots, listing every failure in one exception', function () {
    config([
        'keystone.rate_limits.failed_attempts_per_hour' => 0,
        'keystone.events.enabled' => null,
    ]);

    $boot = fn () => app()->register(KeystoneServiceProvider::class, force: true);

    expect($boot)->toThrow(function (Misconfigured $e) {
        expect($e->failures)->toBe([
            'keystone.rate_limits.failed_attempts_per_hour must be a whole number of at least 1.',
            'keystone.events.enabled must be true or false.',
        ])->and($e->getMessage())->toContain(...$e->failures);
    });
});

describe('the sanity floors', function () {
    it('refuses a limit that isn\'t a whole number of at least 1', function (string $key, mixed $value) {
        config(["keystone.rate_limits.{$key}" => $value]);

        $failures = bootFailures();

        expect($failures)->toBe(["keystone.rate_limits.{$key} must be a whole number of at least 1."]);
    })->with([
        'zero attempts' => ['failed_attempts_per_hour', 0],
        'null attempts' => ['failed_attempts_per_hour', null],
        'negative attempts' => ['failed_attempts_per_hour', -1],
        'a string of attempts' => ['failed_attempts_per_hour', '20'],
        'zero views' => ['requests_per_minute.view', 0],
        'null starts' => ['requests_per_minute.start', null],
        'fractional submits' => ['requests_per_minute.submit', 1.5],
        'false changes' => ['requests_per_minute.change', false],
    ]);

    it('accepts a limit of 1', function () {
        config([
            'keystone.rate_limits.failed_attempts_per_hour' => 1,
            'keystone.rate_limits.requests_per_minute.view' => 1,
        ]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });

    it('refuses request limits that aren\'t a map of the kinds of step', function (mixed $value, string $failure) {
        config(['keystone.rate_limits.requests_per_minute' => $value]);

        $failures = bootFailures();

        expect($failures)->toBe([$failure]);
    })->with([
        'one number' => [10, 'keystone.rate_limits.requests_per_minute must map each kind of step to its limit.'],
        'an unknown kind' => [['view' => 60, 'start' => 10, 'submit' => 10, 'change' => 10, 'poll' => 10], 'keystone.rate_limits.requests_per_minute.poll isn\'t a kind of step.'],
    ]);

    it('refuses a switch that isn\'t true or false', function (mixed $value) {
        config(['keystone.events.enabled' => $value]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.events.enabled must be true or false.']);
    })->with(['null' => [null], 'zero' => [0], 'a string' => ['false']]);

    it('accepts turning recording off', function () {
        config(['keystone.events.enabled' => false]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });
});

describe('the log channel', function () {
    it('refuses a log channel the app doesn\'t define', function (mixed $channel) {
        config(['keystone.log_channel' => $channel]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.log_channel must be null or the name of a channel in logging.channels.']);
    })->with(['an undefined channel' => ['nowhere'], 'an empty name' => [''], 'a number' => [5]]);

    it('accepts a channel the app defines', function () {
        config(['logging.channels.security' => ['driver' => 'single'], 'keystone.log_channel' => 'security']);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });
});

describe('the alert slots', function () {
    it('refuses slots that aren\'t a map of types', function (mixed $slots) {
        config(['keystone.notifications' => $slots]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.notifications must map types of security event to a notification class or null.']);
    })->with(['null' => [null], 'a list' => [[FlakyAlert::class]], 'a string' => [FlakyAlert::class]]);

    it('refuses a slot for a type that isn\'t a security event', function () {
        config(['keystone.notifications.password_changed' => null]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.notifications.password_changed isn\'t a type of security event.']);
    });

    it('refuses a slot that isn\'t null or a notification class', function (mixed $slot) {
        config(['keystone.notifications' => ['sessions.terminated' => $slot]]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.notifications.sessions.terminated must be null or the class name of a notification.']);
    })->with(['a missing class' => ['App\\Notifications\\Missing'], 'a class that isn\'t a notification' => [stdClass::class], 'false' => [false]]);

    it('accepts silencing a type, or naming the app\'s own notification for it', function (?string $slot) {
        config(['keystone.notifications' => ['sessions.terminated' => $slot, 'signed_out' => FlakyAlert::class]]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    })->with(['silenced' => [null], 'the app\'s own' => [FlakyAlert::class]]);
});

describe('the IP-location port', function () {
    it('refuses a plaintext switch that isn\'t true or false', function (mixed $value) {
        config(['keystone.ip_location.allow_plaintext_driver' => $value]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.ip_location.allow_plaintext_driver must be true or false.']);
    })->with(['null' => [null], 'a string' => ['true']]);
});

describe('the absolute session lifetime', function () {
    it('refuses a lifetime that isn\'t null or a whole number of at least 1', function (mixed $seconds) {
        config(['keystone.session.absolute_lifetime_seconds' => $seconds]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.session.absolute_lifetime_seconds must be null or a whole number of at least 1.']);
    })->with(['zero' => [0], 'a negative number' => [-1], 'a string' => ['43200'], 'false' => [false]]);

    it('accepts a lifetime of one second, or none', function (?int $seconds) {
        config(['keystone.session.absolute_lifetime_seconds' => $seconds]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    })->with(['one second' => [1], 'off' => [null]]);
});

describe('the hardening opt-outs', function () {
    it('refuses frame ancestors that aren\'t a list of CSP sources', function (mixed $sources) {
        config(['keystone.hardening.frame_ancestors' => $sources]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.hardening.frame_ancestors must be a list of CSP sources.']);
    })->with([
        'one source' => ["'self'"],
        'a map' => [['partner' => 'https://partner.example']],
        'a directive smuggled in' => [["'self'; script-src *"]],
        'a number' => [[5]],
    ]);

    it('refuses trusted origins that aren\'t a list of origins', function (mixed $origins) {
        config(['keystone.trusted_origins' => $origins]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.trusted_origins must be a list of origins, such as https://example.com.']);
    })->with([
        'one origin' => ['https://other.example'],
        'a host alone' => [['other.example']],
        'an origin with a path' => [['https://other.example/app']],
        'an origin with a query' => [['https://other.example?a=b']],
        'another scheme' => [['ftp://other.example']],
        'a number' => [[5]],
    ]);

    it('accepts origins with a port or a trailing slash', function () {
        config(['keystone.trusted_origins' => ['https://other.example:8443', 'http://localhost:5173/']]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });

    it('refuses site data to clear that isn\'t a list of known kinds', function (mixed $types) {
        config(['keystone.clear_site_data' => $types]);

        $failures = bootFailures();

        expect($failures)->toBe(['keystone.clear_site_data must be a list drawn from cache, cookies, storage and executionContexts.']);
    })->with(['one kind' => ['cache'], 'an unknown kind' => [['cache', 'everything']], 'the wildcard' => [['*']]]);

    it('accepts clearing nothing', function () {
        config(['keystone.clear_site_data' => []]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });
});

describe('the methods allow-list', function () {
    it('accepts bare entries and entries narrowed to surfaces their type serves', function (array $methods) {
        app(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        config(['keystone.methods' => $methods]);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    })->with([
        'bare' => [['form', 'both']],
        'narrowed' => [['form', 'both' => ['challenge']]],
        'every surface listed' => [['both' => ['sign-in', 'challenge']]],
        'nothing' => [[]],
    ]);

    it('refuses a malformed allow-list', function (mixed $methods, string $failure) {
        config(['keystone.methods' => $methods]);

        $failures = bootFailures();

        expect($failures)->toBe([$failure]);
    })->with([
        'one type' => ['form', 'keystone.methods must be null or a list of credential types.'],
        'a bare number' => [[5], 'keystone.methods.0 must name a credential type, or map one to a list of surfaces.'],
        'surfaces as a string' => [['form' => 'sign-in'], 'keystone.methods.form must name a credential type, or map one to a list of surfaces.'],
        'null surfaces' => [['form' => null], 'keystone.methods.form must name a credential type, or map one to a list of surfaces.'],
        'no surfaces' => [['form' => []], 'keystone.methods.form must name a credential type, or map one to a list of surfaces.'],
        'surfaces as a map' => [['form' => ['a' => 'sign-in']], 'keystone.methods.form must name a credential type, or map one to a list of surfaces.'],
        'a type twice' => [['form', 'form'], 'keystone.methods lists [form] twice.'],
        'a type bare and narrowed' => [['form', 'form' => ['sign-in']], 'keystone.methods lists [form] twice.'],
        'an uninstalled type' => [['passkey'], 'keystone.methods lists [passkey], which no installed package registers.'],
        'core\'s reserved type' => [['recovery-code'], 'keystone.methods lists [recovery-code], which no installed package registers.'],
        'a surface the type doesn\'t serve' => [['form' => ['sign-in', 'challenge']], 'keystone.methods lists [form] on [challenge], which it doesn\'t serve.'],
        'an unknown surface' => [['form' => ['sudo']], 'keystone.methods lists [form] on [sudo], which it doesn\'t serve.'],
    ]);

    it('refuses two credential types with one name, however often it is taken', function () {
        app(CredentialTypes::class)->register(new FormType);
        app(CredentialTypes::class)->register(new FormType);

        $failures = bootFailures();

        expect($failures)->toBe(['More than one credential type is named [form].']);
    });

    it('refuses a credential type under core\'s reserved name', function () {
        app(CredentialTypes::class)->register(new FormType(name: 'recovery-code'));

        $failures = bootFailures();

        expect($failures)->toBe(['The credential type name [recovery-code] is reserved for Keystone\'s recovery codes.']);
    });
});

it('refuses a user model on another connection than the one Keystone\'s tables are migrated on', function () {
    config(['auth.providers.users.model' => UserOnOtherConnection::class]);
    $default = config('database.default');

    $failures = bootFailures();

    expect($failures)->toBe(["The user model's connection [other] isn't the default connection [{$default}] Keystone's tables are migrated on."]);
});

describe('in production', function () {
    beforeEach(function () {
        config([
            'app.url' => 'https://example.com',
            'cache.default' => 'database',
            'cache.limiter' => null,
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'session.cookie' => '__Host-example-session',
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
        app()->detectEnvironment(fn () => 'production');
    });

    it('boots a production app that meets every production check', function () {
        $failures = bootFailures();

        expect($failures)->toBe([]);
    });

    it('refuses a setting only production must get right', function (array $config, string $failure) {
        config($config);

        $failures = bootFailures();

        expect($failures)->toBe([$failure]);
    })->with([
        'another guard driver' => [['auth.guards.web.driver' => 'session'], 'auth.guards.web.driver must be keystone in production.'],
        'no type serving sign-in' => [['keystone.methods' => []], 'No credential type listed in keystone.methods serves sign-in.'],
        'an insecure session cookie' => [['session.secure' => null], 'session.secure must be true in production.'],
        'a script-readable session cookie' => [['session.http_only' => false], 'session.http_only must be true in production.'],
        'a cross-site session cookie' => [['session.same_site' => 'none'], 'session.same_site must be lax or strict in production.'],
        'no same-site attribute' => [['session.same_site' => null], 'session.same_site must be lax or strict in production.'],
        'an unprefixed session cookie' => [['session.cookie' => 'example-session'], 'session.cookie must start with __Host- in production.'],
        'a secure-prefixed cookie without a domain' => [['session.cookie' => '__Secure-example-session'], 'session.cookie must start with __Host- in production.'],
        'a host-prefixed cookie with a domain' => [['session.domain' => '.example.com'], 'session.cookie must start with __Secure- when session.domain is set.'],
        'a session cookie on a subpath' => [['session.path' => '/app'], 'session.path must be / in production.'],
        'an array cache' => [['cache.default' => 'array', 'cache.limiter' => 'database'], 'cache.default must use a store that keeps its entries, not array, null or session.'],
        'a null cache' => [['cache.stores.none' => ['driver' => 'null'], 'cache.default' => 'none', 'cache.limiter' => 'database'], 'cache.default must use a store that keeps its entries, not array, null or session.'],
        'a file limiter store' => [['cache.limiter' => 'file'], 'cache.limiter must use a store that increments atomically, not file, storage, array, null or session.'],
        'a session cache' => [['cache.default' => 'session', 'cache.limiter' => 'database'], 'cache.default must use a store that keeps its entries, not array, null or session.'],
        'a session limiter store' => [['cache.limiter' => 'session'], 'cache.limiter must use a store that increments atomically, not file, storage, array, null or session.'],
        'a storage limiter store' => [['cache.stores.disk' => ['driver' => 'storage'], 'cache.limiter' => 'disk'], 'cache.limiter must use a store that increments atomically, not file, storage, array, null or session.'],
        'a file default store for the limiter' => [['cache.default' => 'file'], 'cache.limiter must use a store that increments atomically, not file, storage, array, null or session.'],
        'an http app url' => [['app.url' => 'http://example.com'], 'app.url must use https in production.'],
        'a logging mailer' => [['mail.default' => 'log'], 'mail.default must deliver mail in production, not log or array.'],
        'an array mailer' => [['mail.default' => 'array'], 'mail.default must deliver mail in production, not log or array.'],
        'a null queue' => [['queue.connections.null' => ['driver' => 'null'], 'queue.default' => 'null'], 'queue.default must not be the null queue in production.'],
    ]);

    it('accepts a secure-prefixed session cookie with a domain and a strict same-site attribute', function () {
        config(['session.cookie' => '__Secure-example-session', 'session.domain' => '.example.com', 'session.same_site' => 'strict']);

        $failures = bootFailures();

        expect($failures)->toBe([]);
    });
});

it('skips the checks for the commands that clear or rebuild a cached config', function (string $command) {
    config(['keystone.events.enabled' => null]);
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', $command];

    $failures = bootFailures();

    $_SERVER['argv'] = $argv;
    expect($failures)->toBe([]);
})->with(['config:clear', 'config:cache', 'optimize:clear', 'package:discover']);

it('leaves production\'s checks to production', function () {
    config(['app.url' => 'http://localhost', 'auth.guards.web.driver' => 'session', 'keystone.methods' => [], 'mail.default' => 'log']);

    $failures = bootFailures();

    expect($failures)->toBe([]);
});

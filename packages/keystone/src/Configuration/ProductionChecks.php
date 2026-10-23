<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Auth\KeystoneGuard;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * Check the settings that production refuses and development allows.
 *
 * @internal
 */
class ProductionChecks
{
    /**
     * The cache drivers that keep nothing between requests.
     *
     * @var list<string>
     */
    protected const array FORGETFUL_CACHE_DRIVERS = ['array', 'null'];

    /**
     * The cache drivers that can't count a rate limit atomically.
     *
     * @var list<string>
     */
    protected const array NON_ATOMIC_CACHE_DRIVERS = ['array', 'file', 'null'];

    /**
     * The same-site rules that keep the session cookie off cross-site requests.
     *
     * @var list<string>
     */
    protected const array CROSS_SITE_BLOCKING_SAME_SITE_RULES = ['lax', 'strict'];

    /**
     * The mail transports that deliver nothing.
     *
     * @var list<string>
     */
    protected const array UNDELIVERING_TRANSPORTS = ['array', 'log'];

    /**
     * Create the checks.
     */
    public function __construct(
        protected Config $config,
        protected GuardSetup $guard,
    ) {
        //
    }

    /**
     * Get the failure of each setting that production refuses.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return [
            ...$this->appUrl(),
            ...$this->cacheStore(),
            ...$this->defaultGuard(),
            ...$this->limiterStore(),
            ...$this->mailer(),
            ...$this->queue(),
            ...$this->sessionCookie(),
            ...$this->signInTypes(),
        ];
    }

    /**
     * Get the failure of app.url when it isn't an https URL.
     *
     * @return list<string>
     */
    protected function appUrl(): array
    {
        $url = $this->config->get('app.url');

        if (is_string($url) && Str::startsWith(Str::lower($url), 'https://')) {
            return [];
        }

        return ['app.url must be an https URL in production, since Keystone builds its links from it.'];
    }

    /**
     * Get the failure of the default cache store when its driver keeps nothing between requests.
     *
     * @return list<string>
     */
    protected function cacheStore(): array
    {
        $store = $this->config->string('cache.default');
        $driver = $this->driver('cache.stores', $store, 'driver');

        if (! in_array($driver, static::FORGETFUL_CACHE_DRIVERS, true)) {
            return [];
        }

        return ["The default cache store [{$store}] uses the [{$driver}] driver, which keeps nothing between requests. Set cache.default to a persistent store."];
    }

    /**
     * Get the failure of the default auth guard when it doesn't use the keystone driver.
     *
     * @return list<string>
     */
    protected function defaultGuard(): array
    {
        $driver = $this->guard->driver();

        if ($driver === KeystoneGuard::DRIVER) {
            return [];
        }

        $name = $this->guard->name();
        $uses = $driver === null ? 'has no driver' : "uses the [{$driver}] driver";

        return ["The default auth guard [{$name}] {$uses}, but Keystone's routes need the keystone driver on it."];
    }

    /**
     * Get the failure of the rate limiter's cache store when its driver can't count atomically.
     *
     * @return list<string>
     */
    protected function limiterStore(): array
    {
        $limiter = $this->config->get('cache.limiter');
        $store = is_string($limiter) ? $limiter : $this->config->string('cache.default');
        $driver = $this->driver('cache.stores', $store, 'driver');

        if (! in_array($driver, static::NON_ATOMIC_CACHE_DRIVERS, true)) {
            return [];
        }

        return ["The rate limiter's cache store [{$store}] uses the [{$driver}] driver, which can't count atomically. Set cache.limiter to a store that can."];
    }

    /**
     * Get the failure of the default mailer when its transport delivers nothing.
     *
     * @return list<string>
     */
    protected function mailer(): array
    {
        $mailer = $this->config->string('mail.default');
        $transport = $this->driver('mail.mailers', $mailer, 'transport');

        if (! in_array($transport, static::UNDELIVERING_TRANSPORTS, true)) {
            return [];
        }

        return ["The default mailer [{$mailer}] uses the [{$transport}] transport, which delivers nothing. Set mail.default to a mailer that delivers."];
    }

    /**
     * Get the failure of the default queue connection when its driver drops every job.
     *
     * @return list<string>
     */
    protected function queue(): array
    {
        $connection = $this->config->string('queue.default');
        $driver = $this->driver('queue.connections', $connection, 'driver');

        if ($driver !== 'null') {
            return [];
        }

        return ["The default queue connection [{$connection}] uses the [{$driver}] driver, which drops every job. Set queue.default to a connection that runs them."];
    }

    /**
     * Get the failure of each session cookie setting that weakens the cookie.
     *
     * @return list<string>
     */
    protected function sessionCookie(): array
    {
        $failures = [];

        if ($this->config->get('session.secure') !== true) {
            $failures[] = 'session.secure must be true in production, so the browser sends the session cookie over HTTPS only.';
        }

        if ($this->config->get('session.http_only') !== true) {
            $failures[] = "session.http_only must be true in production, so scripts can't read the session cookie.";
        }

        if (! $this->isSameSiteRule($this->config->get('session.same_site'))) {
            $failures[] = 'session.same_site must be lax or strict in production, so the browser keeps the session cookie off cross-site requests.';
        }

        if (! $this->hasCookiePrefix()) {
            $failures[] = 'session.cookie must start with __Host- in production, or with __Secure- when session.domain is set, so no other subdomain can set the session cookie.';
        }

        if ($this->config->get('session.path') !== '/') {
            $failures[] = 'session.path must be / in production, since a prefixed cookie is only set for the whole site.';
        }

        return $failures;
    }

    /**
     * Get the failure of the methods config when no type it offers serves sign-in.
     *
     * @return list<string>
     */
    protected function signInTypes(): array
    {
        if (Keystone::credentialTypes()->serving(Surface::SIGN_IN) !== []) {
            return [];
        }

        return ['No credential type is offered on sign-in, so no one could sign in. Install a method package and leave it in keystone.methods.'];
    }

    /**
     * Get the driver the named entry of the config group uses, which is its name when the group holds no such entry.
     */
    protected function driver(string $group, string $name, string $key): string
    {
        $driver = $this->config->get("{$group}.{$name}.{$key}");

        return is_string($driver) ? $driver : $name;
    }

    /**
     * Determine if the value is a same-site rule that keeps the cookie off cross-site requests.
     */
    protected function isSameSiteRule(mixed $rule): bool
    {
        return is_string($rule) && in_array(Str::lower($rule), static::CROSS_SITE_BLOCKING_SAME_SITE_RULES, true);
    }

    /**
     * Determine if the session cookie's name carries the prefix its domain setting allows.
     */
    protected function hasCookiePrefix(): bool
    {
        $name = $this->config->get('session.cookie');
        $domain = $this->config->get('session.domain');
        $prefix = $domain === null || $domain === '' ? '__Host-' : '__Secure-';

        return is_string($name) && Str::startsWith($name, $prefix);
    }
}

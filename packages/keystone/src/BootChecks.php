<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Http\Middleware\AddHardeningHeaders;
use ClaudioDekker\Keystone\Http\Middleware\ClearSiteDataOnSessionEnd;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class BootChecks
{
    /**
     * The cache drivers that don't keep their entries across requests.
     */
    protected const array FORGETFUL_CACHE_DRIVERS = ['array', 'null', 'session'];

    /**
     * The cache drivers that don't increment atomically, so the rate limiter can't count on them.
     */
    protected const array NON_ATOMIC_CACHE_DRIVERS = ['file', 'storage', 'array', 'null', 'session'];

    /**
     * The commands that clear or rebuild a cached config, which must run even while it is misconfigured.
     */
    protected const array CONFIG_COMMANDS = ['config:clear', 'config:cache', 'optimize:clear', 'package:discover'];

    /**
     * The mail transports that never deliver.
     */
    protected const array UNDELIVERING_MAIL_TRANSPORTS = ['log', 'array'];

    /**
     * The same-site attributes that keep the session cookie off cross-site requests.
     */
    protected const array SAME_SITE_ATTRIBUTES = ['lax', 'strict'];

    /**
     * Create a new boot checks instance.
     */
    public function __construct(
        protected CredentialTypes $types,
    ) {
        //
    }

    /**
     * Refuse to boot when the configuration is invalid anywhere, or unsafe in production.
     *
     * @throws Misconfigured
     */
    public function check(): void
    {
        if (app()->runningConsoleCommand(self::CONFIG_COMMANDS)) {
            return;
        }

        $failures = [
            ...$this->rateLimitFailures(),
            ...$this->eventFailures(),
            ...$this->hardeningFailures(),
            ...$this->methodFailures(),
            ...$this->connectionFailures(),
            ...(app()->isProduction() ? $this->productionFailures() : []),
        ];

        if ($failures !== []) {
            throw new Misconfigured($failures);
        }
    }

    /**
     * Check that every rate limit is a map of known step kinds, and every allowance a whole number of at least 1.
     *
     * @return list<string>
     */
    protected function rateLimitFailures(): array
    {
        $requests = config('keystone.rate_limits.requests_per_minute');

        if (! is_array($requests)) {
            return [
                'keystone.rate_limits.requests_per_minute must map each kind of step to its limit.',
                ...$this->floorFailures(['keystone.rate_limits.failed_attempts_per_hour']),
            ];
        }

        $unknown = array_diff(array_keys($requests), array_column(StepKind::cases(), 'value'));
        $unknownFailures = array_map(fn (int|string $kind) => "keystone.rate_limits.requests_per_minute.{$kind} isn't a kind of step.", $unknown);
        $keys = array_map(fn (StepKind $kind) => "keystone.rate_limits.requests_per_minute.{$kind->value}", StepKind::cases());

        return [
            ...$this->floorFailures([...$keys, 'keystone.rate_limits.failed_attempts_per_hour']),
            ...array_values($unknownFailures),
        ];
    }

    /**
     * Check that each key holds a whole number of at least 1.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function floorFailures(array $keys): array
    {
        $failing = array_filter($keys, fn (string $key) => ! is_int(config($key)) || config($key) < 1);

        return array_values(array_map(fn (string $key) => "{$key} must be a whole number of at least 1.", $failing));
    }

    /**
     * Check the recording switch and the log channel.
     *
     * @return list<string>
     */
    protected function eventFailures(): array
    {
        $failures = [];
        $channel = config('keystone.log_channel');

        if (! is_bool(config('keystone.events.enabled'))) {
            $failures[] = 'keystone.events.enabled must be true or false.';
        }

        if ($channel !== null && (! is_string($channel) || ! is_array(config("logging.channels.{$channel}")))) {
            $failures[] = 'keystone.log_channel must be null or the name of a channel in logging.channels.';
        }

        return $failures;
    }

    /**
     * Check the hardening floor's opt-outs.
     *
     * @return list<string>
     */
    protected function hardeningFailures(): array
    {
        $failures = [];

        if (! $this->isListOf(config('keystone.hardening.frame_ancestors'), AddHardeningHeaders::isFrameAncestor(...))) {
            $failures[] = 'keystone.hardening.frame_ancestors must be a list of CSP sources.';
        }

        if (! $this->isListOf(config('keystone.trusted_origins'), $this->isOrigin(...))) {
            $failures[] = 'keystone.trusted_origins must be a list of origins, such as https://example.com.';
        }

        if (! $this->isListOf(config('keystone.clear_site_data'), ClearSiteDataOnSessionEnd::isClearable(...))) {
            $failures[] = 'keystone.clear_site_data must be a list drawn from cache, cookies, storage and executionContexts.';
        }

        return $failures;
    }

    /**
     * Determine if the value is a list of strings that each pass the check.
     *
     * @param  callable(string): bool  $check
     *
     * @phpstan-assert-if-true list<string> $value
     */
    protected function isListOf(mixed $value, callable $check): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! $check($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the value is an http or https origin, with an optional port and trailing slash.
     */
    protected function isOrigin(string $value): bool
    {
        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $extras = array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path']);

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $extras === []
            && in_array($parts['path'] ?? '/', ['', '/'], true);
    }

    /**
     * Check the methods allow-list: well-formed entries naming registered types once, on surfaces they serve.
     *
     * @return list<string>
     */
    protected function methodFailures(): array
    {
        $clashes = array_map(fn (string $name) => $name === CredentialTypes::RECOVERY_CODE
            ? "The credential type name [{$name}] is reserved for Keystone's recovery codes."
            : "More than one credential type is named [{$name}].", $this->types->clashes());
        $methods = config('keystone.methods');

        if ($methods === null) {
            return $clashes;
        }

        if (! is_array($methods)) {
            return [...$clashes, 'keystone.methods must be null or a list of credential types.'];
        }

        $failures = $clashes;
        $listed = [];

        foreach ($methods as $key => $entry) {
            $parsed = $this->parseEntry($key, $entry);

            if ($parsed === null) {
                $failures[] = "keystone.methods.{$key} must name a credential type, or map one to a list of surfaces.";

                continue;
            }

            [$name, $surfaces] = $parsed;

            $failures = [...$failures, ...$this->entryFailures($name, $surfaces, $listed)];
            $listed[] = $name;
        }

        return $failures;
    }

    /**
     * Parse an allow-list entry into its type's name and the surfaces it narrows the type to, none for a bare entry.
     *
     * @return array{string, list<string>}|null
     */
    protected function parseEntry(int|string $key, mixed $entry): ?array
    {
        if (is_int($key)) {
            return is_string($entry) ? [$entry, []] : null;
        }

        return $entry !== [] && $this->isListOf($entry, fn () => true) ? [$key, $entry] : null;
    }

    /**
     * Check one allow-list entry against the registered types and the entries before it.
     *
     * @param  list<string>  $surfaces
     * @param  list<string>  $listed
     * @return list<string>
     */
    protected function entryFailures(string $name, array $surfaces, array $listed): array
    {
        $type = $this->types->registered($name);

        if (in_array($name, $listed, true)) {
            return ["keystone.methods lists [{$name}] twice."];
        }

        if ($type === null) {
            return ["keystone.methods lists [{$name}], which no installed package registers."];
        }

        $unserved = array_diff($surfaces, array_keys($type->surfaces()));

        return array_values(array_map(fn (string $surface) => "keystone.methods lists [{$name}] on [{$surface}], which it doesn't serve.", $unserved));
    }

    /**
     * Check that the user model sits on the connection Keystone's tables are migrated on.
     *
     * @return list<string>
     */
    protected function connectionFailures(): array
    {
        $guard = config('auth.defaults.guard');
        $provider = config("auth.guards.{$guard}.provider");
        $model = config("auth.providers.{$provider}.model");

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return [];
        }

        $default = config('database.default');
        $connection = (new $model)->getConnectionName() ?? $default;

        if ($connection === $default) {
            return [];
        }

        return ["The user model's connection [{$connection}] isn't the default connection [{$default}] Keystone's tables are migrated on."];
    }

    /**
     * Check the settings that have an honest use in development but are unsafe in production.
     *
     * @return list<string>
     */
    protected function productionFailures(): array
    {
        return [
            ...$this->guardFailures(),
            ...$this->sessionCookieFailures(),
            ...$this->cacheFailures(),
            ...$this->deliveryFailures(),
        ];
    }

    /**
     * Check that Keystone's routes use the keystone guard, and that some listed type serves sign-in.
     *
     * @return list<string>
     */
    protected function guardFailures(): array
    {
        $failures = [];
        $guard = config('auth.defaults.guard');

        if (config("auth.guards.{$guard}.driver") !== 'keystone') {
            $failures[] = "auth.guards.{$guard}.driver must be keystone in production.";
        }

        if ($this->types->serving(Surface::SIGN_IN) === []) {
            $failures[] = 'No credential type listed in keystone.methods serves sign-in.';
        }

        return $failures;
    }

    /**
     * Check the session cookie's flags, prefix and path.
     *
     * @return list<string>
     */
    protected function sessionCookieFailures(): array
    {
        $failures = [];
        $sameSite = config('session.same_site');
        $domain = config('session.domain');
        $prefix = is_string($domain) && $domain !== '' ? '__Secure-' : '__Host-';

        if (config('session.secure') !== true) {
            $failures[] = 'session.secure must be true in production.';
        }

        if (config('session.http_only') !== true) {
            $failures[] = 'session.http_only must be true in production.';
        }

        if (! is_string($sameSite) || ! in_array(strtolower($sameSite), self::SAME_SITE_ATTRIBUTES, true)) {
            $failures[] = 'session.same_site must be lax or strict in production.';
        }

        if (! str_starts_with((string) config('session.cookie'), $prefix)) {
            $failures[] = $prefix === '__Host-'
                ? 'session.cookie must start with __Host- in production.'
                : 'session.cookie must start with __Secure- when session.domain is set.';
        }

        if (config('session.path') !== '/') {
            $failures[] = 'session.path must be / in production.';
        }

        return $failures;
    }

    /**
     * Check that the cache keeps its entries and the rate limiter's store increments atomically.
     *
     * @return list<string>
     */
    protected function cacheFailures(): array
    {
        $failures = [];
        $default = config('cache.default');
        $limiter = config('cache.limiter') ?? $default;

        if (in_array(config("cache.stores.{$default}.driver"), self::FORGETFUL_CACHE_DRIVERS, true)) {
            $failures[] = 'cache.default must use a store that keeps its entries, not array, null or session.';
        }

        if (in_array(config("cache.stores.{$limiter}.driver"), self::NON_ATOMIC_CACHE_DRIVERS, true)) {
            $failures[] = 'cache.limiter must use a store that increments atomically, not file, storage, array, null or session.';
        }

        return $failures;
    }

    /**
     * Check the app url, mailer and queue that alerts and emailed links depend on.
     *
     * @return list<string>
     */
    protected function deliveryFailures(): array
    {
        $failures = [];
        $mailer = config('mail.default');
        $queue = config('queue.default');

        if (! str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            $failures[] = 'app.url must use https in production.';
        }

        if (in_array(config("mail.mailers.{$mailer}.transport"), self::UNDELIVERING_MAIL_TRANSPORTS, true)) {
            $failures[] = 'mail.default must deliver mail in production, not log or array.';
        }

        if (config("queue.connections.{$queue}.driver") === 'null') {
            $failures[] = 'queue.default must not be the null queue in production.';
        }

        return $failures;
    }
}

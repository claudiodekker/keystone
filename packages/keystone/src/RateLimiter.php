<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Normalizer;
use Throwable;

/**
 * @internal
 */
class RateLimiter
{
    /**
     * The window a request limit counts over.
     */
    public const int REQUEST_WINDOW_SECONDS = 60;

    /**
     * The window a failed-attempt limit counts over.
     */
    public const int FAILED_ATTEMPT_WINDOW_SECONDS = 3600;

    /**
     * How long a refusal asks the client to wait while the store is down.
     */
    public const int OUTAGE_RETRY_AFTER_SECONDS = 60;

    /**
     * How long before its window ends a failed attempt can no longer be given back.
     */
    public const int GIVE_BACK_MARGIN_SECONDS = 1;

    /**
     * The bytes an IPv4-mapped IPv6 address starts with.
     */
    public const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * The bytes of an IPv6 address its /64 network keeps.
     */
    public const int IPV6_NETWORK_BYTES = 8;

    /**
     * The source part of a failed attempt from a browser that isn't a known device.
     */
    public const string OTHER_SOURCE = 'other';

    /**
     * The address part shared by every request without a usable IP address.
     */
    public const string UNKNOWN_ADDRESS = 'unknown';

    /**
     * Create a new rate limiter instance.
     */
    public function __construct(
        protected Request $request,
        protected KeystoneGuard $guard,
        protected SecurityEventRecorder $recorder = new SecurityEventRecorder,
    ) {
        //
    }

    /**
     * Count the request against its step kind's limits, refusing once one is spent.
     *
     * @throws Throttled
     */
    public function hitRequest(StepKind $kind): void
    {
        $keys = $this->requestKeys($kind);

        try {
            $counts = $this->countHits($keys);
            $spent = array_keys(array_filter($counts, fn (int $count) => $count > $kind->allowance()));
            $retryAfterSeconds = $this->retryAfter($spent);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($spent === []) {
            return;
        }

        if (in_array($kind->allowance() + 1, $counts, true)) {
            $this->recordRequestTrip();
        }

        throw new Throttled($retryAfterSeconds);
    }

    /**
     * Take a failed attempt for the account, or for the identifier that named none, before its proof is checked.
     *
     * @throws Throttled
     */
    public function takeFailedAttempt(Flow $flow, CredentialType $type, (Model&KeystoneUser)|null $account, string $identifier): TakenAttempt
    {
        $key = $this->key('failed-attempt', [
            $this->subject($account, $identifier),
            $type->name(),
            $flow->value,
            self::OTHER_SOURCE,
        ]);

        try {
            $count = $this->counter()->increment($key, self::FAILED_ATTEMPT_WINDOW_SECONDS);
            $retryAfterSeconds = $this->retryAfter([$key]);
            $windowEndsAt = $this->windowEndsAt($key);
        } catch (Throwable $e) {
            report($e);

            throw new Throttled(self::OUTAGE_RETRY_AFTER_SECONDS);
        }

        $allowance = config()->integer('keystone.rate_limits.failed_attempts_per_hour');

        if ($count === $allowance + 1) {
            $this->recorder->record(
                SecurityEventType::LIMIT_TRIPPED,
                account: $account,
                flow: $flow->value,
                credentialType: $type->name(),
                reason: 'keystone.failed_attempt_limit',
            );

            $this->dispatchLockout();
        }

        if ($count > $allowance) {
            throw new Throttled($retryAfterSeconds);
        }

        return new TakenAttempt($key, $windowEndsAt);
    }

    /**
     * Give back a failed attempt that didn't count, unless the window it was taken in has ended.
     */
    public function giveBack(TakenAttempt $attempt): void
    {
        try {
            $windowEndsAt = $this->windowEndsAt($attempt->key);
            $availableInSeconds = $this->counter()->availableIn($attempt->key);

            // Laravel's decrement starts a new window when the key expires first.
            if ($windowEndsAt === $attempt->windowEndsAt && $availableInSeconds > self::GIVE_BACK_MARGIN_SECONDS) {
                $this->counter()->decrement($attempt->key, self::FAILED_ATTEMPT_WINDOW_SECONDS);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Get the request limit's keys: the address's, and the signed-in account's.
     *
     * @return non-empty-list<string>
     */
    protected function requestKeys(StepKind $kind): array
    {
        $keys = [$this->key('request', [$kind->value, 'address', $this->address()])];
        $accountId = $this->guard->id();

        if ($accountId !== null) {
            $keys[] = $this->key('request', [$kind->value, 'account', (string) $accountId]);
        }

        return $keys;
    }

    /**
     * Count one hit on each key, returning each key's count.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    protected function countHits(array $keys): array
    {
        $counts = [];

        foreach ($keys as $key) {
            $counts[$key] = $this->counter()->increment($key, self::REQUEST_WINDOW_SECONDS);
        }

        return $counts;
    }

    /**
     * Record that a request limit refused its first request in the window, about the signed-in account if any.
     */
    protected function recordRequestTrip(): void
    {
        /** @var (Model&KeystoneUser)|null $account */
        $account = $this->guard->user();

        $this->recorder->record(
            SecurityEventType::LIMIT_TRIPPED,
            account: $account,
            reason: 'keystone.request_limit',
        );

        $this->dispatchLockout();
    }

    /**
     * Dispatch Laravel's Lockout event, as Fortify does, reporting a failing listener rather than changing the refusal.
     */
    protected function dispatchLockout(): void
    {
        rescue(fn () => event(new Lockout($this->request)));
    }

    /**
     * Get the seconds until the latest of the spent keys expires, at least one.
     *
     * @param  list<string>  $spent
     */
    protected function retryAfter(array $spent): int
    {
        $seconds = array_map(fn (string $key) => $this->counter()->availableIn($key), $spent);

        return max(1, ...$seconds);
    }

    /**
     * Get the key part naming the subject: the account's id, or the identifier folded the way Fortify folds it.
     *
     * @param  (Model&KeystoneUser)|null  $account
     */
    protected function subject(?Model $account, string $identifier): string
    {
        if ($account !== null) {
            return 'account:'.$account->getKey();
        }

        $folded = Str::lower(Str::transliterate($identifier));

        return "identifier:{$folded}";
    }

    /**
     * Get the request's address part: IPv4 as is, IPv4-mapped IPv6 unwrapped, other IPv6 masked to its /64.
     */
    protected function address(): string
    {
        $ip = $this->request->ip();

        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN_ADDRESS;
        }

        $packed = (string) inet_pton($ip);

        $masked = match (true) {
            ! str_contains($ip, ':') => $packed,
            str_starts_with($packed, self::IPV4_MAPPED_PREFIX) => substr($packed, strlen(self::IPV4_MAPPED_PREFIX)),
            default => substr($packed, 0, self::IPV6_NETWORK_BYTES).str_repeat("\0", self::IPV6_NETWORK_BYTES),
        };

        return (string) inet_ntop($masked);
    }

    /**
     * Build a key from its parts, canonicalised and hashed under the purpose's subkey of the app key.
     *
     * @param  list<string>  $parts
     */
    protected function key(string $purpose, array $parts): string
    {
        $canonical = array_map(function (string $part) {
            $normalized = Normalizer::normalize($part, Normalizer::FORM_C);

            return mb_strtolower($normalized === false ? $part : $normalized);
        }, $parts);

        $message = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $subkey = hash_hmac('sha256', "keystone.rate-limiter.{$purpose}", $this->appKey(), binary: true);
        $digest = hash_hmac('sha256', $message, $subkey);

        return "keystone:{$digest}";
    }

    /**
     * Get the app key the keys are hashed under.
     */
    protected function appKey(): string
    {
        return app('encrypter')->getKey();
    }

    /**
     * Get Laravel's rate limiter, counting in the limiter cache store.
     */
    protected function counter(): CacheRateLimiter
    {
        return app(CacheRateLimiter::class);
    }

    /**
     * Get the timestamp the window of the key's count ends at, or null when it has none.
     */
    protected function windowEndsAt(string $key): ?int
    {
        $storeName = config('cache.limiter');
        $timer = Cache::store(is_string($storeName) ? $storeName : null)->get("{$key}:timer");

        return is_numeric($timer) ? (int) $timer : null;
    }
}

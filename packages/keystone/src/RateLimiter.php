<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Exceptions\Throttled;
use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Normalizer;
use Throwable;

/**
 * @internal
 */
class RateLimiter
{
    /**
     * The flow part of the failed-attempt key a guessable type's failures share.
     */
    public const string SHARED_FLOWS = 'second-factor';

    /**
     * How many wrong answers a guessable type may get over its ceiling's window, whatever the hourly allowance.
     */
    public const int SHARED_CEILING = 100;

    /**
     * The window a guessable type's ceiling counts over.
     */
    public const int SHARED_CEILING_WINDOW_SECONDS = 86400;

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
        protected RequestContext $context,
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
        $limits = $this->failedAttemptLimits($flow, $type, subject: $this->subject($account, $identifier), source: $this->source($account));

        try {
            $counts = [];
            $incremented = [];

            // The ceiling counts only attempts the hourly limit let through, so a spent hour can't burn the day's allowance.
            foreach ($limits as $index => $limit) {
                $hourSpent = $index > 0 && $counts[0] > $limits[0]['allowance'];
                $counts[$index] = $hourSpent ? (int) $this->counter()->attempts($limit['key']) : $this->counter()->increment($limit['key'], $limit['window_seconds']);
                $incremented[$index] = ! $hourSpent;
            }

            $spent = array_filter($limits, fn (array $limit, int $index) => $counts[$index] > $limit['allowance'], ARRAY_FILTER_USE_BOTH);
            $retryAfterSeconds = $this->retryAfter(array_column($spent, 'key'));
            $taken = array_map(fn (array $limit) => new TakenCount($limit['key'], windowSeconds: $limit['window_seconds'], windowEndsAt: $this->windowEndsAt($limit['key'])), $limits);
        } catch (Throwable $e) {
            report($e);

            throw new Throttled(self::OUTAGE_RETRY_AFTER_SECONDS);
        }

        $tripped = array_filter($limits, fn (array $limit, int $index) => $incremented[$index] && $counts[$index] === $limit['allowance'] + 1, ARRAY_FILTER_USE_BOTH);

        if ($tripped !== []) {
            $this->recorder->record(
                SecurityEventType::LIMIT_TRIPPED,
                account: $account,
                flow: $flow->value,
                credentialType: $type->name(),
                reason: 'keystone.failed_attempt_limit',
            );

            $this->dispatchLockout();
        }

        if ($spent !== []) {
            throw new Throttled($retryAfterSeconds);
        }

        return new TakenAttempt($taken);
    }

    /**
     * Give back a failed attempt that didn't count, unless the window it was taken in has ended.
     */
    public function giveBack(TakenAttempt $attempt): void
    {
        foreach ($attempt->counts as $count) {
            try {
                $windowEndsAt = $this->windowEndsAt($count->key);
                $availableInSeconds = $this->counter()->availableIn($count->key);

                // Laravel's decrement starts a new window when the key expires first.
                if ($windowEndsAt === $count->windowEndsAt && $availableInSeconds > self::GIVE_BACK_MARGIN_SECONDS) {
                    $this->counter()->decrement($count->key, $count->windowSeconds);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Get the failed-attempt limits a wrong answer from the source counts against.
     *
     * @return non-empty-list<array{key: string, window_seconds: int, allowance: int}>
     */
    protected function failedAttemptLimits(Flow $flow, CredentialType $type, string $subject, string $source): array
    {
        $shared = $type->sharesFailedAttempts() && $flow->sharesFailedAttempts();

        $hourly = [
            'key' => $this->key('failed-attempt', [$subject, $type->name(), $shared ? self::SHARED_FLOWS : $flow->value, $source]),
            'window_seconds' => self::FAILED_ATTEMPT_WINDOW_SECONDS,
            'allowance' => config()->integer('keystone.rate_limits.failed_attempts_per_hour'),
        ];

        if (! $shared) {
            return [$hourly];
        }

        $daily = [
            'key' => $this->key('failed-attempt-ceiling', [$subject, $type->name(), $source]),
            'window_seconds' => self::SHARED_CEILING_WINDOW_SECONDS,
            'allowance' => self::SHARED_CEILING,
        ];

        return [$hourly, $daily];
    }

    /**
     * Get the source part of a failed attempt: the id of the account's known device the browser is, else other.
     */
    protected function source((Model&KeystoneUser)|null $account): string
    {
        if ($account === null) {
            return self::OTHER_SOURCE;
        }

        $device = (new KnownDevices($account))->deviceIdOf($this->request);

        return $device === null ? self::OTHER_SOURCE : "device:{$device}";
    }

    /**
     * Get the request limit's keys: the address's, and that of the account the session names, signed in or pending.
     *
     * @return non-empty-list<string>
     */
    protected function requestKeys(StepKind $kind): array
    {
        $keys = [$this->key('request', [$kind->value, 'address', $this->address()])];
        $accountId = $this->guard->namedAccountId();

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
            alert: false,
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

        return max([1, ...$seconds]);
    }

    /**
     * Get the key part naming the subject: the account's id, or the identifier normalized the way addresses are stored and compared.
     *
     * @param  (Model&KeystoneUser)|null  $account
     */
    protected function subject(?Model $account, string $identifier): string
    {
        if ($account !== null) {
            return 'account:'.$account->getKey();
        }

        return 'identifier:'.Addresses::normalize($identifier);
    }

    /**
     * Get the request's address part: IPv4 as is, IPv4-mapped IPv6 unwrapped, other IPv6 masked to its /64.
     */
    protected function address(): string
    {
        return Subnet::networkOf($this->context->ipAddress) ?? self::UNKNOWN_ADDRESS;
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

<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
     * How many failed attempts an account may have per credential type and flow in a window.
     */
    public const int FAILED_ATTEMPT_ALLOWANCE = 20;

    /**
     * How long a refusal asks the client to wait while the store is down.
     */
    public const int OUTAGE_RETRY_AFTER_SECONDS = 60;

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
            $spent = $this->spentKeys($keys, $kind->allowance());
            $retryAfterSeconds = $this->retryAfter($spent);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($spent !== []) {
            throw new Throttled($retryAfterSeconds);
        }
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
            $count = $this->store()->increment($key, self::FAILED_ATTEMPT_WINDOW_SECONDS);
            $availableInSeconds = $this->store()->availableIn($key);
        } catch (Throwable $e) {
            report($e);

            throw new Throttled(self::OUTAGE_RETRY_AFTER_SECONDS);
        }

        if ($count > self::FAILED_ATTEMPT_ALLOWANCE) {
            throw new Throttled(max(1, $availableInSeconds));
        }

        return new TakenAttempt($key, windowEndsAt: now()->getTimestamp() + $availableInSeconds);
    }

    /**
     * Give back a failed attempt that didn't count, unless the window it was taken in has ended.
     */
    public function giveBack(TakenAttempt $attempt): void
    {
        try {
            $availableInSeconds = $this->store()->availableIn($attempt->key);
            $windowEndsAt = now()->getTimestamp() + $availableInSeconds;

            if ($availableInSeconds > 0 && $windowEndsAt === $attempt->windowEndsAt) {
                $this->store()->decrement($attempt->key, self::FAILED_ATTEMPT_WINDOW_SECONDS);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Get the request limit's keys: the address's, and the account's the session names.
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
     * Count one hit on each key, returning the keys that went over the allowance.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function spentKeys(array $keys, int $allowance): array
    {
        $spent = [];

        foreach ($keys as $key) {
            $count = $this->store()->increment($key, self::REQUEST_WINDOW_SECONDS);

            if ($count > $allowance) {
                $spent[] = $key;
            }
        }

        return $spent;
    }

    /**
     * Get the seconds until the latest of the spent keys expires, at least one.
     *
     * @param  list<string>  $spent
     */
    protected function retryAfter(array $spent): int
    {
        $seconds = array_map(fn (string $key) => $this->store()->availableIn($key), $spent);

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
        $mappedPrefix = str_repeat("\0", 10)."\xff\xff";

        $masked = match (true) {
            strlen($packed) === 4 => $packed,
            str_starts_with($packed, $mappedPrefix) => substr($packed, 12),
            default => substr($packed, 0, 8).str_repeat("\0", 8),
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
    protected function store(): CacheRateLimiter
    {
        return app(CacheRateLimiter::class);
    }
}

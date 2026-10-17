<?php

namespace ClaudioDekker\Keystone\RateLimits;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Http\Subnet;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Count requests and failed attempts against their allowances in Laravel's rate-limiter store, and refuse a spent one as throttled.
 *
 * Keys are hashed under a subkey of the app key, so the store holds no identifier or address in clear.
 *
 * @internal
 */
class Limiter
{
    /**
     * The label of the app key's subkey for rate limit keys.
     */
    protected const string PURPOSE = 'keystone.rate_limit';

    /**
     * The key part every request without a usable IP address shares.
     */
    protected const string UNKNOWN_ADDRESS = 'unknown';

    /**
     * Create the limiter.
     */
    public function __construct(
        protected Config $config,
        protected RateLimiter $counts,
        protected Request $request,
        protected SessionState $session,
    ) {
        //
    }

    /**
     * Count a request of the step kind against the IP address and, separately, the signed-in account, and refuse it once either count is spent.
     *
     * @throws ThrottledException
     */
    public function takeRequest(StepKind $kind): void
    {
        $allowance = $this->config->integer('keystone.rate_limits.requests_per_minute.'.$kind->value);
        $keys = [$this->key(Limit::REQUEST, $kind->value, 'address', $this->address())];
        $account = $this->session->account();

        if ($account !== null) {
            $keys[] = $this->key(Limit::REQUEST, $kind->value, 'account', (string) $account->getAuthIdentifier());
        }

        $spent = collect($keys)
            ->map(fn (string $key) => $this->take($key, Limit::REQUEST))
            ->filter(fn (CountedAttempt $attempt) => $attempt->isOver($allowance));

        if ($spent->isEmpty()) {
            return;
        }

        throw ThrottledException::retryAfter($this->secondsUntil($spent->max->availableAt));
    }

    /**
     * Count a failed attempt of the type before it verifies anything, and refuse it once the account's count is spent.
     *
     * An identifier that names no account is counted by its address as the account lookup compares it.
     *
     * @throws ThrottledException
     */
    public function takeFailedAttempt(CredentialType $type, Surface $surface, ?KeystoneUser $account, string $identifier): CountedAttempt
    {
        $flow = $this->session->flow($surface) ?? throw new LogicException("No flow counts failed attempts on the [{$surface->value}] surface.");
        $key = $this->key(Limit::FAILED_ATTEMPT, ...$this->subject($account, $identifier), ...[$type->name(), $flow->value]);
        $allowance = $this->config->integer('keystone.rate_limits.failed_attempts_per_hour');

        $attempt = $this->take($key, Limit::FAILED_ATTEMPT);

        if (! $attempt->isOver($allowance)) {
            return $attempt;
        }

        throw ThrottledException::retryAfter($this->secondsUntil($attempt->availableAt));
    }

    /**
     * Give a counted attempt back, unless the window it was counted in has ended.
     */
    public function giveBack(CountedAttempt $attempt): void
    {
        $secondsLeft = $this->counts->availableIn($attempt->key);

        if ($secondsLeft === 0 || ! $attempt->endsAt(Date::now()->getTimestamp() + $secondsLeft)) {
            return;
        }

        $this->counts->decrement($attempt->key, $secondsLeft);
    }

    /**
     * Take one count of the key with one atomic increment, and get the count it reached with the end of its window.
     */
    protected function take(string $key, Limit $limit): CountedAttempt
    {
        $count = $this->counts->increment($key, $limit->windowSeconds());
        $availableAt = Date::now()->getTimestamp() + $this->counts->availableIn($key);

        return new CountedAttempt($key, $limit, $count, $availableAt);
    }

    /**
     * Get the seconds from now until the timestamp.
     */
    protected function secondsUntil(int $timestamp): int
    {
        return $timestamp - Date::now()->getTimestamp();
    }

    /**
     * Get the key parts of whom an attempt names: the account by its id, else the identifier as the account lookup compares it.
     *
     * @return array{string, string}
     */
    protected function subject(?KeystoneUser $account, string $identifier): array
    {
        if ($account !== null) {
            return ['account', (string) $account->getAuthIdentifier()];
        }

        return ['identifier', EmailAddress::normalize($identifier)];
    }

    /**
     * Get the request's IP address as the request limit counts it, or the part every request without a usable one shares.
     */
    protected function address(): string
    {
        $ipAddress = RequestContext::of($this->request)?->ipAddress;

        if ($ipAddress === null) {
            return static::UNKNOWN_ADDRESS;
        }

        return Subnet::counted($ipAddress) ?? static::UNKNOWN_ADDRESS;
    }

    /**
     * Get the store key of the limit's parts, hashed under the rate limit subkey of the app key.
     */
    protected function key(Limit $limit, string ...$parts): string
    {
        $message = json_encode([$limit->value, ...$parts], JSON_THROW_ON_ERROR);

        $appKey = (string) $this->config->get('app.key');
        $subkey = hash_hmac('sha256', static::PURPOSE, $appKey);

        return 'keystone:rate_limit:'.hash_hmac('sha256', $message, $subkey);
    }
}

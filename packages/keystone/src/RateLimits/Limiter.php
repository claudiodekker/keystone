<?php

namespace ClaudioDekker\Keystone\RateLimits;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Session\SessionState;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as Config;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Count failed attempts against their allowance in Laravel's rate-limiter store, and refuse a spent one as throttled.
 *
 * Keys are hashed under a subkey of the app key, so the store holds no identifier in clear.
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
     * Create the limiter.
     */
    public function __construct(
        protected Config $config,
        protected RateLimiter $counts,
        protected SessionState $session,
    ) {
        //
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

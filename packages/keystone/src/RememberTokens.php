<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @internal
 */
class RememberTokens
{
    /**
     * The name of the cookie that restores a sign-in on return.
     */
    public const string COOKIE = '__Host-keystone_remember';

    /**
     * How many random bytes the cookie's value holds.
     */
    protected const int VALUE_BYTES = 32;

    /**
     * Create a new remember tokens instance on the model's connection.
     */
    public function __construct(
        protected Model $model,
    ) {
        //
    }

    /**
     * Issue a token for the account on the credential epoch, returning its id and the cookie that carries it.
     *
     * @return array{int, Cookie}
     */
    public function issue(int|string $accountId, int $epoch): array
    {
        $value = bin2hex(random_bytes(self::VALUE_BYTES));
        $now = Date::now();
        $expiresAt = $now->copy()->addSeconds(static::lifetimeSeconds());

        $id = $this->query()->insertGetId([
            'user_id' => $accountId,
            'token_hash' => static::digest($value),
            'credential_epoch' => $epoch,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return [$id, static::cookie($value, $expiresAt)];
    }

    /**
     * Forget the token.
     */
    public function forget(int $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * Get the cookie that makes the browser drop its remember-me cookie.
     */
    public static function expiredCookie(): Cookie
    {
        return static::cookie(null, Date::now()->subYears(5));
    }

    /**
     * Determine if remember-me is on.
     */
    public static function isOffered(): bool
    {
        return static::lifetimeSeconds() > 0;
    }

    /**
     * Get how long a token lasts from the sign-in that issued it.
     */
    protected static function lifetimeSeconds(): int
    {
        return config()->integer('keystone.remember.lifetime_seconds');
    }

    /**
     * Get the remember-me cookie holding the value until the time.
     */
    protected static function cookie(#[\SensitiveParameter] ?string $value, CarbonInterface $expiresAt): Cookie
    {
        return Cookie::create(self::COOKIE, $value, expire: $expiresAt, secure: true, sameSite: Cookie::SAMESITE_LAX);
    }

    /**
     * Get the digest stored for a cookie's value.
     */
    protected static function digest(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Get a query for the remember tokens table on the model's connection.
     */
    protected function query(): Builder
    {
        return $this->model->getConnection()->table('user_remember_tokens');
    }
}

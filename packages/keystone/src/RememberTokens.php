<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

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
        $value = static::mint();
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
     * Get the token the cookie's value names, live or not.
     */
    public function find(#[\SensitiveParameter] string $value): ?RememberToken
    {
        $row = $this->query()->where('token_hash', static::digest($value))->first();

        if ($row === null) {
            return null;
        }

        return new RememberToken(
            id: (int) $row->id,
            accountId: $row->user_id,
            epoch: (int) $row->credential_epoch,
            expiresAt: CarbonImmutable::parse($row->expires_at),
        );
    }

    /**
     * Forget the token.
     */
    public function forget(int $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * Hand the token a new value on the credential epoch its account moved to, keeping its id and expiry, when it was live on the epoch that moved.
     */
    public function reissue(int $id, int $movedFrom, int $movedTo): ?Cookie
    {
        $expiresAt = $this->query()
            ->where('id', $id)
            ->where('credential_epoch', $movedFrom)
            ->where('expires_at', '>', Date::now())
            ->value('expires_at');

        if ($expiresAt === null) {
            return null;
        }

        $value = static::mint();

        $moved = $this->query()->where('id', $id)->where('credential_epoch', $movedFrom)->update([
            'token_hash' => static::digest($value),
            'credential_epoch' => $movedTo,
        ]);

        return $moved === 0 ? null : static::cookie($value, CarbonImmutable::parse($expiresAt));
    }

    /**
     * Get the value of the remember-me cookie the request carries, when it has the shape of one Keystone hands out.
     */
    public static function cookieOf(Request $request): ?string
    {
        $value = $request->cookies->get(static::COOKIE);
        $pattern = sprintf('/\A[0-9a-f]{%d}\z/', self::VALUE_BYTES * 2);

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
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
     * Mint the value a remember-me cookie carries.
     */
    protected static function mint(): string
    {
        return bin2hex(random_bytes(self::VALUE_BYTES));
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

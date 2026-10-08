<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
class KnownDevices
{
    /**
     * The name of the cookie that marks a browser as a known device.
     */
    public const string COOKIE = '__Host-keystone_device';

    /**
     * How many random bytes name the device, in the part of the value that never changes.
     */
    protected const int DEVICE_BYTES = 16;

    /**
     * How many random bytes the part of the value that every sign-in replaces holds.
     */
    protected const int SECRET_BYTES = 32;

    /**
     * Create a new known devices instance for the account's devices.
     *
     * @throws LogicException when the account has no key
     */
    public function __construct(
        protected Model&KeystoneUser $account,
    ) {
        if ($account->getKey() === null) {
            throw new LogicException('Known devices belong to a saved account.');
        }
    }

    /**
     * Determine if the request's device cookie marks a device the account has signed in from within the retention.
     */
    public function isKnown(Request $request): bool
    {
        return $this->deviceIdOf($request) !== null;
    }

    /**
     * Get the id of the device the request's device cookie marks, when the account has signed in from it within the retention.
     */
    public function deviceIdOf(Request $request): ?int
    {
        $value = static::cookieOf($request);

        if ($value === null) {
            return null;
        }

        $id = $this->query()
            ->where('device_hash', static::deviceDigest($value))
            ->where('cookie_hash', static::digest($value))
            ->where('last_seen_at', '>', Date::now()->subSeconds(static::retentionSeconds()))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Determine if the request's device cookie names a device of the account, seen within the retention, that a later sign-in handed a new value.
     */
    public function isReused(Request $request): bool
    {
        $value = static::cookieOf($request);

        if ($value === null) {
            return false;
        }

        return $this->query()
            ->where('device_hash', static::deviceDigest($value))
            ->where('cookie_hash', '!=', static::digest($value))
            ->where('last_seen_at', '>', Date::now()->subSeconds(static::retentionSeconds()))
            ->exists();
    }

    /**
     * Mint a new value for the browser, move every account that knew its previous value to it, and remember it for the account, returning the device cookie that carries it.
     *
     * The value keeps the previous one's device part, so the account's row follows the browser and another browser still holding a replaced value can be told from a new one.
     */
    public function remember(Request $request, RequestContext $context): Cookie
    {
        $previous = static::cookieOf($request);
        $device = $previous === null ? bin2hex(random_bytes(self::DEVICE_BYTES)) : Str::before($previous, '.');
        $value = $device.'.'.bin2hex(random_bytes(self::SECRET_BYTES));
        $digest = static::digest($value);
        $now = Date::now();

        $this->account->getConnection()->transaction(function () use ($previous, $context, $value, $digest, $now) {
            if ($previous !== null) {
                static::table($this->account)->where('cookie_hash', static::digest($previous))->update(['cookie_hash' => $digest]);
            }

            static::table($this->account)->upsert([
                'user_id' => $this->account->getKey(),
                'device_hash' => static::deviceDigest($value),
                'cookie_hash' => $digest,
                'user_agent' => $this->encrypt($context->userAgent),
                'ip_address' => $this->encrypt($context->ipAddress),
                'last_seen_at' => $now,
                'created_at' => $now,
            ], ['user_id', 'device_hash'], ['cookie_hash', 'user_agent', 'ip_address', 'last_seen_at']);
        });

        return static::cookie($value, $now->copy()->addSeconds(static::retentionSeconds()));
    }

    /**
     * Forget every device the account knows.
     */
    public function forget(): void
    {
        $this->query()->delete();
    }

    /**
     * Forget every device of every account.
     */
    public static function forgetAll(Model $users): void
    {
        static::table($users)->delete();
    }

    /**
     * Forget every device unseen for the retention, returning how many were forgotten.
     */
    public static function prune(Model $users): int
    {
        return static::table($users)->where('last_seen_at', '<=', Date::now()->subSeconds(static::retentionSeconds()))->delete();
    }

    /**
     * Get the value of the device cookie the request carries, when it has the shape of one Keystone hands out.
     */
    protected static function cookieOf(Request $request): ?string
    {
        $value = $request->cookies->get(static::COOKIE);
        $pattern = sprintf('/\A[0-9a-f]{%d}\.[0-9a-f]{%d}\z/', self::DEVICE_BYTES * 2, self::SECRET_BYTES * 2);

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    /**
     * Get the device cookie holding the value until the time.
     */
    protected static function cookie(#[\SensitiveParameter] string $value, CarbonInterface $expiresAt): Cookie
    {
        return Cookie::create(static::COOKIE, $value, expire: $expiresAt, secure: true, sameSite: Cookie::SAMESITE_LAX);
    }

    /**
     * Get the digest stored for a cookie's value.
     */
    public static function digest(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Get the digest stored for the device part of a cookie's value.
     */
    protected static function deviceDigest(#[\SensitiveParameter] string $value): string
    {
        return static::digest(Str::before($value, '.'));
    }

    /**
     * Get how long a device stays known without a sign-in from it.
     */
    public static function retentionSeconds(): int
    {
        return config()->integer('keystone.retention.known_devices_seconds');
    }

    /**
     * Encrypt a display label, keeping a missing one missing.
     */
    protected function encrypt(?string $value): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }

    /**
     * Get a query for the known devices table on the model's connection.
     */
    protected static function table(Model $model): Builder
    {
        return $model->getConnection()->table('user_known_devices');
    }

    /**
     * Get a query for the account's devices.
     */
    protected function query(): Builder
    {
        return static::table($this->account)->where('user_id', $this->account->getKey());
    }
}

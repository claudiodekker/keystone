<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
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
     * Create a new known devices instance on the model's connection.
     */
    public function __construct(
        protected Model $model,
    ) {
        //
    }

    /**
     * Determine if the cookie's value marks a device the account has signed in from within the retention.
     */
    public function isKnown(int|string $accountId, #[\SensitiveParameter] ?string $value): bool
    {
        return $this->idOf($accountId, $value) !== null;
    }

    /**
     * Get the id of the device the cookie's value marks, when the account has signed in from it within the retention.
     *
     * The id outlives the value, which every sign-in from the browser replaces.
     */
    public function idOf(int|string $accountId, #[\SensitiveParameter] ?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = $this->query()
            ->where('user_id', $accountId)
            ->where('device_hash', static::deviceDigest($value))
            ->where('cookie_hash', static::digest($value))
            ->where('last_seen_at', '>', Date::now()->subSeconds(static::retentionSeconds()))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Determine if the cookie's value names a device of the account, seen within the retention, that a later sign-in handed a new value.
     */
    public function isReused(int|string $accountId, #[\SensitiveParameter] string $value): bool
    {
        return $this->query()
            ->where('user_id', $accountId)
            ->where('device_hash', static::deviceDigest($value))
            ->where('cookie_hash', '!=', static::digest($value))
            ->where('last_seen_at', '>', Date::now()->subSeconds(static::retentionSeconds()))
            ->exists();
    }

    /**
     * Mint a new value for the browser, move every account that knew its previous value to it, and remember it for the account.
     *
     * The value keeps the previous one's device part, so the account's row follows the browser and another browser still holding a replaced value can be told from a new one.
     */
    public function remember(int|string $accountId, #[\SensitiveParameter] ?string $previous, ?string $userAgent, ?string $ipAddress): string
    {
        $device = $previous === null ? bin2hex(random_bytes(self::DEVICE_BYTES)) : Str::before($previous, '.');
        $value = $device.'.'.bin2hex(random_bytes(self::SECRET_BYTES));
        $digest = static::digest($value);
        $now = Date::now();

        $this->model->getConnection()->transaction(function () use ($accountId, $previous, $userAgent, $ipAddress, $value, $digest, $now) {
            if ($previous !== null) {
                $this->query()->where('cookie_hash', static::digest($previous))->update(['cookie_hash' => $digest]);
            }

            $this->query()->upsert([
                'user_id' => $accountId,
                'device_hash' => static::deviceDigest($value),
                'cookie_hash' => $digest,
                'user_agent' => $this->encrypt($userAgent === null ? null : Str::substr($userAgent, 0, SecurityEventRecorder::USER_AGENT_LENGTH)),
                'ip_address' => $this->encrypt($ipAddress),
                'last_seen_at' => $now,
                'created_at' => $now,
            ], ['user_id', 'device_hash'], ['cookie_hash', 'user_agent', 'ip_address', 'last_seen_at']);
        });

        return $value;
    }

    /**
     * Forget every device the account knows.
     */
    public function forget(int|string $accountId): void
    {
        $this->query()->where('user_id', $accountId)->delete();
    }

    /**
     * Forget every device of every account.
     */
    public function forgetAll(): void
    {
        $this->query()->delete();
    }

    /**
     * Forget every device unseen for the retention, returning how many were forgotten.
     */
    public function prune(): int
    {
        return $this->query()->where('last_seen_at', '<=', Date::now()->subSeconds(static::retentionSeconds()))->delete();
    }

    /**
     * Get the value of the device cookie the request carries, when it has the shape of one Keystone hands out.
     */
    public static function cookieOf(Request $request): ?string
    {
        $value = $request->cookies->get(static::COOKIE);
        $pattern = sprintf('/\A[0-9a-f]{%d}\.[0-9a-f]{%d}\z/', self::DEVICE_BYTES * 2, self::SECRET_BYTES * 2);

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
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
    protected function query(): Builder
    {
        return $this->model->getConnection()->table('user_known_devices');
    }
}

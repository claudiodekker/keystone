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
     * How many random bytes a device cookie's value holds.
     */
    protected const int VALUE_BYTES = 32;

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
            ->where('cookie_hash', static::digest($value))
            ->where('last_seen_at', '>', Date::now()->subSeconds(static::retentionSeconds()))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Mint a new value for the browser, move every account that knew its previous value to it, and remember it for the account.
     */
    public function remember(int|string $accountId, #[\SensitiveParameter] ?string $previous, ?string $userAgent, ?string $ipAddress): string
    {
        $value = bin2hex(random_bytes(self::VALUE_BYTES));
        $digest = static::digest($value);
        $now = Date::now();

        $this->model->getConnection()->transaction(function () use ($accountId, $previous, $userAgent, $ipAddress, $digest, $now) {
            if ($previous !== null) {
                $this->query()->where('cookie_hash', static::digest($previous))->update(['cookie_hash' => $digest]);
            }

            $this->query()->upsert([
                'user_id' => $accountId,
                'cookie_hash' => $digest,
                'user_agent' => $this->encrypt($userAgent === null ? null : Str::substr($userAgent, 0, SecurityEventRecorder::USER_AGENT_LENGTH)),
                'ip_address' => $this->encrypt($ipAddress),
                'last_seen_at' => $now,
                'created_at' => $now,
            ], ['user_id', 'cookie_hash'], ['user_agent', 'ip_address', 'last_seen_at']);
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
     * Get the value of the device cookie the request carries.
     */
    public static function cookieOf(Request $request): ?string
    {
        $value = $request->cookies->get(static::COOKIE);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Get the digest stored for a cookie's value.
     */
    public static function digest(#[\SensitiveParameter] string $value): string
    {
        return hash('sha256', $value);
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

<?php

namespace ClaudioDekker\Keystone\Devices;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @internal
 */
class KnownDevices
{
    /**
     * Create the known devices.
     */
    public function __construct(
        protected Config $config,
        protected Connection $connection,
        protected CookieJar $cookies,
        protected Recorder $recorder,
        protected Request $request,
    ) {
        //
    }

    /**
     * Hand the browser signing in to the account a fresh device cookie, and determine if the account knew the browser.
     *
     * Every account that knew the browser's old cookie knows the fresh one, so a copy of the old one matches nothing once this sign-in is done.
     * A cookie of a device the account knew whose value a later sign-in replaced comes from one of two browsers sharing it, which is recorded.
     */
    public function signIn(KeystoneUser $account): bool
    {
        $sent = $this->sentCookie();
        $fresh = $sent?->rekeyed() ?? DeviceCookie::generate();
        $device = $sent === null ? null : $this->deviceOf($account, $sent);

        $this->connection->transaction(fn () => $this->rekey($account, $sent, $fresh));

        $this->cookies->queue($this->makeCookie($fresh));

        if ($device !== null && ! hash_equals($device->digest, $sent->digest())) {
            $this->recorder->record(SecurityEventType::DEVICE_COOKIE_REUSED, account: $account);
        }

        return $device !== null;
    }

    /**
     * Get the id of the account's known device the browser is, by the whole value of its cookie, or null when it is none.
     *
     * A browser holding a value a later sign-in replaced is none, so a copy of a cookie never shares its owner's failed attempts.
     */
    public function idOf(KeystoneUser $account): int|string|null
    {
        $sent = $this->sentCookie();

        if ($sent === null) {
            return null;
        }

        return UserKnownDevice::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('digest', $sent->digest())
            ->where('last_seen_at', '>', $this->seenSince())
            ->value('id');
    }

    /**
     * Get the device cookie the browser sent, or null when it sent none Keystone could have handed out.
     */
    protected function sentCookie(): ?DeviceCookie
    {
        $value = $this->request->cookie(DeviceCookie::NAME);

        return DeviceCookie::fromValue(is_string($value) ? $value : null);
    }

    /**
     * Move every account that knew the sent cookie to the fresh one, and record the account's sign-in from the device.
     */
    protected function rekey(KeystoneUser $account, ?DeviceCookie $sent, DeviceCookie $fresh): void
    {
        $context = RequestContext::of($this->request);

        if ($sent !== null) {
            UserKnownDevice::query()
                ->where('digest', $sent->digest())
                ->update(['digest' => $fresh->digest()]);
        }

        $device = UserKnownDevice::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('device_digest', $fresh->deviceDigest())
            ->firstOrNew();

        $device->forceFill([
            'user_id' => $account->getAuthIdentifier(),
            'digest' => $fresh->digest(),
            'device_digest' => $fresh->deviceDigest(),
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'last_seen_at' => Date::now(),
        ])->save();
    }

    /**
     * Get the account's device the cookie names, whatever its value, if the account signed in from it within the retention.
     */
    protected function deviceOf(KeystoneUser $account, DeviceCookie $cookie): ?UserKnownDevice
    {
        return UserKnownDevice::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('device_digest', $cookie->deviceDigest())
            ->where('last_seen_at', '>', $this->seenSince())
            ->first();
    }

    /**
     * Get the time a device must have been seen after to count as known.
     */
    protected function seenSince(): CarbonImmutable
    {
        $retention = $this->config->integer('keystone.retention.known_devices_seconds');

        return Date::now()->toImmutable()->subSeconds($retention);
    }

    /**
     * Make the cookie the browser keeps for the retention: host-only, sent only over HTTPS, hidden from scripts, and kept off cross-site requests.
     */
    protected function makeCookie(DeviceCookie $cookie): Cookie
    {
        $retention = $this->config->integer('keystone.retention.known_devices_seconds');

        return new Cookie(
            name: DeviceCookie::NAME,
            value: $cookie->value(),
            expire: Date::now()->getTimestamp() + $retention,
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}

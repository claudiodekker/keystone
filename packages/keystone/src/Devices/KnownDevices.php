<?php

namespace ClaudioDekker\Keystone\Devices;

use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Models\UserKnownDevice;
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
        protected Request $request,
    ) {
        //
    }

    /**
     * Hand the browser signing in to the account a fresh device cookie, and determine if the account knew the browser.
     *
     * Every account that knew the browser's old cookie knows the fresh one, so a copy of the old one matches nothing once this sign-in is done.
     */
    public function signIn(KeystoneUser $account): bool
    {
        $value = $this->request->cookie(DeviceCookie::NAME);
        $sent = DeviceCookie::fromValue(is_string($value) ? $value : null);
        $fresh = $sent?->rekeyed() ?? DeviceCookie::generate();

        $known = $this->connection->transaction(fn () => $this->rekey($account, $sent, $fresh));

        $this->cookies->queue($this->makeCookie($fresh));

        return $known;
    }

    /**
     * Move every account that knew the sent cookie to the fresh one, record the account's sign-in from the device, and determine if the account knew it.
     */
    protected function rekey(KeystoneUser $account, ?DeviceCookie $sent, DeviceCookie $fresh): bool
    {
        $known = $sent !== null && $this->knows($account, $sent);
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

        return $known;
    }

    /**
     * Determine if the account signed in with the cookie within the retention.
     */
    protected function knows(KeystoneUser $account, DeviceCookie $cookie): bool
    {
        return UserKnownDevice::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->where('digest', $cookie->digest())
            ->where('last_seen_at', '>', $this->seenSince())
            ->exists();
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

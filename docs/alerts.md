# Alerts

An alert is a notification that tells an account's owner something happened to its security. Each type of event has a slot in `notifications`: the notification class Keystone sends for that type, or `null` to send none. Keystone's own alert is a mail. A class of your own picks its channels in `via()`, and reaches the owner through each of their addresses, which Keystone hands it routed for mail. Today `account.suspended`, `account.unsuspended`, `challenge.abandoned`, `credential.added`, `device_cookie.reused`, `limit.tripped`, `recovery_code.used`, `sessions.terminated`, `signed_in`, `sudo.failed` and `sudo.network_changed` alert by default:

```php
'notifications' => [
    'account.suspended' => SecurityAlert::class,
    'account.unsuspended' => SecurityAlert::class,
    'challenge.abandoned' => SecurityAlert::class,
    'credential.added' => SecurityAlert::class,
    'device_cookie.reused' => SecurityAlert::class,
    'limit.tripped' => SecurityAlert::class,
    'proof.rejected' => null,
    'recovery_code.used' => SecurityAlert::class,
    'recovery_codes.generated' => null,
    'request.rejected' => null,
    'session.ended' => null,
    'sessions.terminated' => SecurityAlert::class,
    'sign_in.held' => null,
    'sign_in.voided' => null,
    'signed_in' => SecurityAlert::class,
    'signed_out' => null,
    'sudo.failed' => SecurityAlert::class,
    'sudo.granted' => null,
    'sudo.network_changed' => SecurityAlert::class,
    'sudo.revoked' => null,
],
```

Two of these alert only some of the time. `signed_in` alerts only for a browser the account doesn't know, as [New devices](#new-devices) explains. `limit.tripped` alerts only when a failed-attempt limit trips, never a request limit. Anyone who knows an address can trip its count on the sign-in page, so an owner can get one of these alerts per credential type each hour. Set the slot to `null` if that is more than your users want.

There is no switch that silences every alert at once. To stop one type, set its slot to `null`. Alerts are not de-duplicated, so each event sends its own, except the abandoned challenges one sweep finds for an account, which share [one alert](#abandoned-challenges).

## Who gets one

An alert goes to every verified address of the account, one each. An account that holds no verified address gets it at its unverified addresses instead, except an address another account holds verified. An address someone typed into a form never gets one.

When a change to the account causes the event, Keystone reads the addresses before the change. An address that the change, or the transaction around it, removed still gets the alert.

## What it says

Every default slot is `ClaudioDekker\Keystone\Notifications\SecurityAlert`, which picks a view and translation keys for its type. The mail is plain HTML, with every value escaped and no links. It says what happened and when, in UTC, the IP address of the request that caused it and where that address is, and the device. A mail that sums up several events names each IP address and device once, the first five of each, and counts the rest, and gives the time of the first. The mail about a spent recovery code also says how many codes the account has left. It ends with "If this wasn't you, sign in and review your security settings.", except the mail about a suspension, which can't ask its owner to sign in and ends with "If you think this is a mistake, contact the site's administrators." Its wording sits in Keystone's `keystone::alerts` translations.

The device is the platform and browser parsed from the user agent, such as "Chrome on Mac", or "Unknown device". The alert parses the user agent while the request records the event, so the queue carries only the device and never the user agent. It looks the location up later, on the queue worker, while it builds the mail. A lookup during the request would slow down only the requests for accounts that exist, and tell an attacker which addresses have one.

Keystone names devices and locations through two packages it suggests but doesn't require:

```bash
composer require matomo/device-detector
composer require stevebauman/location
```

Without `matomo/device-detector`, every device is "Unknown device". Without `stevebauman/location`, the mail gives the IP address alone. With it installed, Keystone asks the drivers in your `location` config in order, and skips every driver whose URL is plain `http`, because it would send the address to a third party in clear. The package's default driver is one of them. Choose a driver that uses `https`, or a local MaxMind database, or set `ip_location.allow_plaintext_driver` to `true` to allow plain `http`.

To use another service, bind your own implementation of `ClaudioDekker\Keystone\Contracts\IpLocation` or `ClaudioDekker\Keystone\Contracts\SessionInfo` in a service provider. Neither may throw. Return `null` for a location or device you don't know.

## New devices

Signing in from a browser the account hasn't used before alerts its owner. Keystone recognises a browser by a cookie, never by its IP address or user agent, since an attacker can copy a user agent and share a network. A known browser on a new network sends no alert, and a new browser on a network the account used before sends one.

Every completed sign-in, whichever step completes it, hands the browser the `__Host-keystone_device` cookie. Its value is 128 random bits that name the device and never change, a dot, and 256 random bits that the next sign-in replaces. The cookie is host-only, `Secure`, `HttpOnly` and `SameSite=Lax`, lives for the retention, and is not encrypted by Laravel, since Keystone only compares its digest. The `user_known_devices` table keeps the SHA-256 digest of the whole value and of the device part for each account, with the user agent and the last IP address encrypted, as labels only.

Each sign-in replaces the value, and every account that knew the old value knows the new one. A copied or planted value is known only until the browser it came from signs in again. After that, a browser holding the copy names a device of the account with a value a later sign-in replaced. Keystone can't tell which of the two browsers is the owner's, so it records `device_cookie.reused`, which alerts, and the account follows the browser that signed in. The other browser now holds the replaced value, so its next sign-in alerts the same way. Each account that knew the device hears about it at its own next sign-in, and only that account's record changes.

A browser is known for 90 days after the account last signed in from it, set by `retention.known_devices_seconds`:

```php
'retention' => [
    'known_devices_seconds' => 60 * 60 * 24 * 90,
],
```

A browser unseen for that long alerts again at its next sign-in, and `keystone:prune` deletes its row every night, as the [operator commands](operator-commands.md#pruning) page explains. An operator who ends an account's sessions makes it forget every browser, so the owner's next sign-in from each one alerts. Suspending an account and other changes that end sessions keep them.

Some sign-ins alert though nothing was copied. A browser that never got the response of its sign-in still holds the replaced value, so its next sign-in alerts as reused, and so does the second of two sign-ins one browser sent at once, such as a form submitted twice. A browser that cleared its cookies, including through `clear_site_data` listing `cookies`, signs in as a new device.

A known device also keeps its own [failed-attempt count](security.md#the-failed-attempt-limit), so failures from other browsers never lock the owner out of a browser they used before.

## Abandoned challenges

Someone who passes an account's first factor and never answers its [challenge](challenge.md) may know its password. When a browser that isn't a known device of the account is held at the challenge, Keystone keeps a row in `user_pending_challenges` with the account, the IP address and the user agent, the last two encrypted. A browser whose device cookie holds a value a later sign-in replaced isn't known here either. Holding the sign-in at enrollment, or from a known device, keeps no row.

Only passing the challenge deletes the row, or signing in to the account from that browser without it, once the account no longer owes the challenge. Cancelling, letting the pending sign-in expire after 15 minutes, or an epoch move that voids it all keep it, so nobody can stop the alert by walking away. A browser that passes the first factor again keeps the row of its first hold and that hold's time.

Every 5 minutes, Keystone runs `keystone:alert-abandoned-challenges` on one server, with no overlap, as the [operator commands](operator-commands.md#abandoned-challenges) page explains. It records `challenge.abandoned` for every row at least 7 minutes old and deletes the rows. Each account gets one alert for all of its own: how many there were, from which IP addresses and on which devices. The 7 minutes are fixed, with no setting. The rows of an account that was deleted go with it, with nothing recorded or sent.

## Sending your own

Name your own notification class in a slot to send it in place of Keystone's, or to alert for a type that sends nothing by default. Keystone builds it with the event as its first argument, so its constructor can't ask the container for anything else. For `challenge.abandoned`, the other events the alert sums up follow as further arguments, which a variadic parameter such as `UserSecurityEvent ...$others` collects:

```php
// config/keystone.php
use App\Notifications\SignedOutAlert;

'notifications' => [
    'signed_out' => SignedOutAlert::class,
],
```

```php
namespace App\Notifications;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignedOutAlert extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public string $occurredAt;

    public function __construct(UserSecurityEvent $event)
    {
        $this->occurredAt = $event->occurred_at->toDateTimeString();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You signed out')
            ->line("Your account signed out at {$this->occurredAt} UTC.");
    }
}
```

Keystone sends it on demand, to each address in turn, so `$notifiable` is the address and not your user. The event may be unsaved when its row could not be written, so copy what the alert needs in the constructor rather than keeping the event to reload on the queue. Implement `ShouldQueue` and `ShouldBeEncrypted`, as Keystone's own alert does, so your `jobs` and `failed_jobs` tables never hold an IP address or an address in clear.

Keystone refuses to boot with a slot that would lose every alert of its type: a slot for a type that doesn't exist, a value that is neither `null` nor a notification class, a class whose constructor can't take the event alone, or `SecurityAlert` on a type it has no mail for. `SecurityAlert` has a mail only for the types that alert by default, so any other type needs a class of your own.

## Queue worker

Alerts are queued, so they need a queue worker. With the `sync` connection they are sent during the request that caused them. Their payloads are encrypted with your app key.

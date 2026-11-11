# Security events

Keystone records what happens to an account's security as security events. Every event goes through one recorder, which Keystone calls from the code that made the event happen. The recorder runs four steps, each on its own, so a step that fails is reported to the exception handler and the others still run:

1. It writes a log line.
2. It writes a row of `user_security_events`, when the event is for an account.
3. It mails the account's owner an [alert](#alerts), when the event is for an account and its type's slot holds a notification.
4. It dispatches the `ClaudioDekker\Keystone\Events\SecurityEventRecorded` event with the whole entry.

An anonymous event, such as a refused sign-in for an address no account holds, is only a log line. Identical ones, with the same type, IP address and path, are logged once per 60 seconds, counted in the default cache under a hash keyed by a key derived from your app key, so the cache never holds an address. While the cache fails, every one is logged. An operator's anonymous event, such as ending every account's sessions, is logged every time.

## Configuration

```php
'events' => [
    'enabled' => true,
],

'log_channel' => null,
```

Set `events.enabled` to `false` to turn the recorder off in any environment. Nothing is logged, written, mailed or dispatched then. Only `false` turns it off.

Set `log_channel` to the name of a channel in your `logging.php` to send the log lines there. Leave it `null` to use the app's default channel.

## Types

| Type | Recorded when |
|---|---|
| `account.suspended` | An operator suspended the account. |
| `account.unsuspended` | An operator lifted the account's suspension. |
| `credential.added` | A credential was added to the account, such as a second factor enrolled while a sign-in was held at enrollment. |
| `device_cookie.reused` | A browser signed in with the device cookie of a [known device](#new-devices) whose value a later sign-in replaced, so two browsers may share it. |
| `limit.tripped` | A rate limit refused its first attempt of the window. The reason names the limit: `keystone.daily_failed_attempt_limit`, `keystone.failed_attempt_limit` or `keystone.request_limit`. |
| `proof.rejected` | A sign-in was refused for an account that the typed address named, or a challenge answer was refused for the held account. A TOTP code from a step already accepted has the reason `totp.replayed`, and an account's last recovery code, kept while recovery codes are required, has the reason `recovery-code.last_code`. |
| `recovery_code.used` | A recovery code answered the challenge and was spent. |
| `recovery_codes.generated` | A set of recovery codes was stored. A first set alerts nobody. |
| `request.rejected` | A request was refused before it ran. The reason says why: `keystone.cross_site_request`. The event is anonymous. |
| `session.ended` | Keystone ended the session. The reason says why: `keystone.expired` when it passed its absolute lifetime, `keystone.epoch` when an operator ended the account's sessions or suspended it after it signed in, `keystone.demoted` when the account came to owe [enrollment](enrollment.md#signed-in-sessions-that-newly-owe). |
| `sessions.terminated` | An operator ended every session of the account. Ending every account's sessions records one about nobody, with reason `keystone.every_account`. |
| `sign_in.held` | A right first factor was held for what the account owes. The reason names the stage: `keystone.challenge` or `keystone.enrollment`. A challenge answer that moves on to enrollment records it again. |
| `sign_in.voided` | A pending sign-in was dropped because its account's credential epoch moved or the account was suspended since the hold. The reason is `keystone.epoch`. |
| `signed_in` | A session signed in, on its first factor, at the challenge or at the end of enrollment. `known_device` says whether the browser was a [known device](#new-devices) of the account. |
| `signed_out` | The user signed out. |

## Fields

Every event has the same fields, whatever its type. There is no free-form metadata.

| Field | Holds |
|---|---|
| `id` | A ULID, the same in the row, the log line and the dispatched event. |
| `occurred_at` | When the event happened, in UTC, whatever your app's timezone is. |
| `type` | One of the types above. |
| `user_id` | The account the event is for, or null for an anonymous event. The column has no foreign key, so a removed account's rows keep its id. |
| `actor` | Who made it happen: `user`, `operator` or `system`. An event an operator command or job records is the operator's. |
| `flow` | The flow the request was in, `sign-in`, `challenge` or `enrollment`, or null when it was in none, such as signing out. |
| `credential_type` | The name of the credential type involved, if any. |
| `credential_id` | The credential involved, if any. A rejection names a credential only when it belongs to the account the attempt named. |
| `credential_label` | The credential's label as it was at the time, when it has one. |
| `reason` | A short code of `a-z`, `0-9`, `.` and `_`, at most 64 characters, prefixed by the credential type or by `keystone.`. Anything else is stored as `<type>.invalid_reason`. |
| `ip_address` | The client's address, encrypted at rest. |
| `location` | The address's location, encrypted at rest, when it was looked up. |
| `user_agent` | The client's user agent, cleaned of invalid UTF-8 and control characters, cut to 512 characters and encrypted at rest. |
| `known_device` | Whether the browser was a [known device](#new-devices) of the account, on `signed_in`. Null on every other type. |
| `request_id` | A ULID minted once per request, shared by every event the request records. |
| `operator` | The operator a command or job names, cut to 64 characters with control characters replaced by spaces, or null when no operator acted. |

The `ip_address`, `location` and `user_agent` fields are left out of the model's array form. Read them from the model's attributes when a page needs them.

## The log line

The message of every log line is `keystone.security_event`. Every field travels in the context array, with the IP address, location and user agent in clear, since the log is the operator's. A value that reaches a field, such as a user agent, is JSON-escaped in the context and cannot forge a second line.

```
[2026-10-17 15:04:05] local.INFO: keystone.security_event {"id":"01k7qz1g3w8ndq0y9q1ztp7s6e","occurred_at":"2026-10-17T15:04:05.000000Z","type":"signed_in","user_id":42,"actor":"user","flow":"sign-in","credential_type":"password","credential_id":7,"credential_label":null,"reason":null,"ip_address":"203.0.113.9","location":null,"user_agent":"Mozilla/5.0 (X11; Linux x86_64)","known_device":null,"request_id":"01k7qz1g3v2f0h0y9q1ztp7s6d","operator":null}
```

## The request context

Keystone adds one piece of global middleware after the app's trusted-proxy middleware. It reads the client's IP address and user agent once per request, and keeps them with the address's subnet, the path and a new request id. Every event in the request carries that context, so set your trusted proxies the way Laravel documents, and the recorded address is the client's.

## Alerts

An alert is a mail that tells an account's owner something happened to its security. Each type of event has a slot in `notifications`: the notification class Keystone sends for that type, or `null` to send none. Today `account.suspended`, `account.unsuspended`, `credential.added`, `device_cookie.reused`, `limit.tripped`, `recovery_code.used`, `sessions.terminated` and `signed_in` alert by default:

```php
'notifications' => [
    'account.suspended' => SecurityAlert::class,
    'account.unsuspended' => SecurityAlert::class,
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
],
```

Two of these alert only some of the time. `signed_in` alerts only for a browser the account doesn't know, as [New devices](#new-devices) explains. `limit.tripped` alerts only when a failed-attempt limit trips, never a request limit. Anyone who knows an address can trip its count on the sign-in page, so an owner can get one of these mails per credential type each hour. Set the slot to `null` if that is more than your users want.

There is no switch that silences every alert at once. To stop one type, set its slot to `null`. Alerts are not de-duplicated, so each event sends its own.

### Who gets one

An alert goes to every verified address of the account, one mail each. An account that holds no verified address gets it at its unverified addresses instead, except an address another account holds verified. An address someone typed into a form never gets one.

When a change to the account causes the event, Keystone reads the addresses before the change. An address that the change, or the transaction around it, removed still gets the alert.

### What it says

Every default slot is `ClaudioDekker\Keystone\Notifications\SecurityAlert`, which picks a view and translation keys for its type. The mail is plain HTML, with every value escaped and no links. It says what happened and when, in UTC, the IP address of the request that caused it and where that address is, and the device. The mail about a spent recovery code also says how many codes the account has left. It ends with "If this wasn't you, sign in and review your security settings.", except the mail about a suspension, which can't ask its owner to sign in and ends with "If you think this is a mistake, contact the site's administrators." Its wording sits in Keystone's `keystone::alerts` translations.

The device is the platform and browser parsed from the user agent, such as "Chrome on Mac", or "Unknown device". The alert parses the user agent while the request records the event, so the queue carries only the device and never the user agent. It looks the location up later, on the queue worker, while it builds the mail. A lookup during the request would slow down only the requests for accounts that exist, and tell an attacker which addresses have one.

Keystone names devices and locations through two packages it suggests but doesn't require:

```bash
composer require matomo/device-detector
composer require stevebauman/location
```

Without `matomo/device-detector`, every device is "Unknown device". Without `stevebauman/location`, the mail gives the IP address alone. With it installed, Keystone asks the drivers in your `location` config in order, and skips every driver whose URL is plain `http`, because it would send the address to a third party in clear. The package's default driver is one of them. Choose a driver that uses `https`, or a local MaxMind database, or set `ip_location.allow_plaintext_driver` to `true` to allow plain `http`.

To use another service, bind your own implementation of `ClaudioDekker\Keystone\Contracts\IpLocation` or `ClaudioDekker\Keystone\Contracts\SessionInfo` in a service provider. Neither may throw. Return `null` for a location or device you don't know.

### New devices

Signing in from a browser the account hasn't used before mails its owner. Keystone recognises a browser by a cookie, never by its IP address or user agent, since an attacker can copy a user agent and share a network. A known browser on a new network sends no alert, and a new browser on a network the account used before sends one.

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

### Sending your own

Name your own notification class in a slot to send it in place of Keystone's, or to alert for a type that sends nothing by default. Keystone builds it with the event as its only argument, so its constructor can't ask the container for anything else:

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

Keystone sends it on demand, to each address in turn, so `$notifiable` is the address and not your user. The event may be unsaved when its row could not be written, so copy what the mail needs in the constructor rather than keeping the event to reload on the queue. Implement `ShouldQueue` and `ShouldBeEncrypted`, as Keystone's own alert does, so your `jobs` and `failed_jobs` tables never hold an IP address or an address in clear.

Keystone refuses to boot with a slot that would lose every alert of its type: a slot for a type that doesn't exist, a value that is neither `null` nor a notification class, a class whose constructor can't take the event alone, or `SecurityAlert` on a type it has no mail for. `SecurityAlert` has a mail only for the types that alert by default, so any other type needs a class of your own.

### Queue worker

Alerts are queued, so they need a queue worker. With the `sync` connection they are sent during the request that caused them. Their payloads are encrypted with your app key.

## Listening

Listen for `SecurityEventRecorded` to act on events in your app. The `entry` property holds the event as a `ClaudioDekker\Keystone\Models\UserSecurityEvent`, and your listener branches on its `type`. The entry may be unsaved when its row could not be written, so read it rather than reloading it by id.

Your listener runs inside the request that recorded the event, inside the sign-in's timing floor for a refused sign-in. Queue a listener that does slow work, such as sending mail or calling an API.

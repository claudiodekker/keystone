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
| `limit.tripped` | A rate limit refused its first attempt of the window. The reason names the limit: `keystone.failed_attempt_limit` or `keystone.request_limit`. |
| `proof.rejected` | A sign-in was refused for an account that the typed address named, or a challenge answer was refused for the held account. A TOTP code from a step already accepted has the reason `totp.replayed`, and an account's last recovery code, kept while recovery codes are required, has the reason `recovery-code.last_code`. |
| `recovery_code.used` | A recovery code answered the challenge and was spent. |
| `request.rejected` | A request was refused before it ran. The reason says why: `keystone.cross_site_request`. The event is anonymous. |
| `session.ended` | Keystone ended the session. The reason says why: `keystone.expired` when it passed its absolute lifetime, `keystone.epoch` when an operator ended the account's sessions or suspended it after it signed in. |
| `sessions.terminated` | An operator ended every session of the account. Ending every account's sessions records one about nobody, with reason `keystone.every_account`. |
| `sign_in.held` | A right first factor was held for what the account owes. The reason names the stage: `keystone.challenge`. |
| `sign_in.voided` | A pending sign-in was dropped because its account's credential epoch moved or the account was suspended since the hold. The reason is `keystone.epoch`. |
| `signed_in` | A session signed in, on its first factor or at the challenge. |
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
| `flow` | The flow the request was in, `sign-in` or `challenge`, or null when it was in none, such as signing out. |
| `credential_type` | The name of the credential type involved, if any. |
| `credential_id` | The credential involved, if any. A rejection names a credential only when it belongs to the account the attempt named. |
| `credential_label` | The credential's label as it was at the time, when it has one. |
| `reason` | A short code of `a-z`, `0-9`, `.` and `_`, at most 64 characters, prefixed by the credential type or by `keystone.`. Anything else is stored as `<type>.invalid_reason`. |
| `ip_address` | The client's address, encrypted at rest. |
| `location` | The address's location, encrypted at rest, when it was looked up. |
| `user_agent` | The client's user agent, cleaned of invalid UTF-8 and control characters, cut to 512 characters and encrypted at rest. |
| `known_device` | Whether the browser was a known device of the account, when that was checked. |
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

An alert is a mail that tells an account's owner something happened to its security. Each type of event has a slot in `notifications`: the notification class Keystone sends for that type, or `null` to send none. Today `account.suspended`, `account.unsuspended`, `recovery_code.used` and `sessions.terminated` alert by default:

```php
'notifications' => [
    'account.suspended' => SecurityAlert::class,
    'account.unsuspended' => SecurityAlert::class,
    'limit.tripped' => null,
    'proof.rejected' => null,
    'recovery_code.used' => SecurityAlert::class,
    'request.rejected' => null,
    'session.ended' => null,
    'sessions.terminated' => SecurityAlert::class,
    'sign_in.held' => null,
    'sign_in.voided' => null,
    'signed_in' => null,
    'signed_out' => null,
],
```

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

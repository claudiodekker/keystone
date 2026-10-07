# Security alerts

Keystone mails an account's owner when something happens to their account that they should know about, such as an operator ending every session of it. Each alert is about one [security event](security-events.md), or about an account's [abandoned challenges](#abandoned-challenges) together, sent by the recorder that records it, so nothing in your app needs to be wired up.

Alerts are queued. Run a queue worker, or they are never sent: `queue.default` set to `sync` sends them inside the request, and production refuses to boot on the `null` queue (see [Configuration](configuration.md#boot-checks)).

## Types that alert

| Type | Alerted when |
|---|---|
| `account.suspended` | an operator suspended the account (see [Operator commands](operator-commands.md#suspending-accounts)) |
| `account.unsuspended` | an operator lifted the account's suspension |
| `challenge.abandoned` | a sign-in from a browser that isn't one of the account's [known devices](#new-devices) passed its first factor and hasn't passed the [challenge](challenge.md) 7 minutes later (see [Abandoned challenges](#abandoned-challenges)) |
| `credential.added` | a credential was added to the account, such as a second factor at [enrollment](enrollment.md) |
| `device_cookie.reused` | a browser signed in with a [device cookie](#new-devices) that a later sign-in had already replaced, so two browsers held the same cookie |
| `limit.tripped` | wrong answers spent one of the account's [failed-attempt counts](rate-limiting.md#locking-an-accounts-owner-out), once per count and window; a spent request limit alerts nobody |
| `recovery_code.used` | a [recovery code](challenge.md#recovery-codes) answered the challenge; the mail says how many codes the account has left |
| `recovery_codes.generated` | a new set of recovery codes replaced the account's codes; a first set alerts nobody |
| `sessions.terminated` | an operator ended every session of the account, unless they passed `--no-alert` (see [Operator commands](operator-commands.md#ending-sessions)); ending every account's sessions with `--all` alerts nobody |
| `signed_in` | the account signed in from a browser that isn't one of its [known devices](#new-devices) |

## Who gets them

Every verified address of the account gets its own mail. While an account has no verified address, its unverified addresses get one each instead. The addresses are read before the change that caused the event, so an address a change removes still hears about it.

There is no de-duplication: two events send two alerts, however alike. Only abandoned challenges share one.

## New devices

Every sign-in hands the browser a `__Host-keystone_device` cookie: a random value, host-only, `Secure`, `HttpOnly` and `SameSite=Lax`. Laravel doesn't encrypt it: Keystone adds it to the `EncryptCookies` middleware's exceptions itself. Keystone stores only SHA-256 digests of it, in `user_known_devices`, with the browser's user agent and IP address encrypted as labels. A browser holding a value the account knows is one of its known devices, and signing in from it doesn't alert. A browser it doesn't know, or one unseen for longer than `retention.known_devices_seconds` (90 days), alerts with `signed_in`. The IP address and user agent never decide: a known device on a new network doesn't alert, and a new browser on the owner's usual network does.

Each sign-in hands the browser a fresh value and moves every account that knew the old one on to it. A copied cookie, or one planted in someone's browser, stops being known at that browser's next sign-in, and a value Keystone never handed out is never known. Several accounts signing in from one browser each know the same value.

The first part of the value names the device and stays the same, so Keystone can tell a browser that still holds a replaced value from a new one. When such a browser signs in, two browsers held the same cookie: one of them copied it. Keystone alerts the owner with `device_cookie.reused` as well as `signed_in`, and the account now knows the browser that signed in rather than the other one. The first alert arrives when the second of the two browsers signs in, whichever that is, and each later sign-in from the browser left behind alerts again. Each account that knew the device is alerted at its own next sign-in. A sign-in whose response never reached the browser alerts the same way at that browser's next sign-in.

A known device earns no trust beyond its own [failed-attempt count](rate-limiting.md#locking-an-accounts-owner-out): it is never spared a challenge, an enrollment or an alert of any other type.

An operator ending an account's sessions with `keystone:end-sessions` forgets its known devices, and `--all` forgets every account's, so each browser's next sign-in alerts. Keystone's scheduled `keystone:prune-known-devices` task deletes devices unseen for the retention every night, on one server, so run Laravel's scheduler.

## Abandoned challenges

A sign-in that passes its first factor and hasn't passed the [challenge](challenge.md) 7 minutes later suggests someone has the account's first factor, such as its password, and not its second. Keystone tells the owner. A pending sign-in lasts 15 minutes, so the challenge can still be passed after the alert is sent, and the mail doesn't say that nobody signed in.

When a sign-in is held at the challenge from a browser that isn't one of the account's known devices, Keystone writes a row to `user_pending_challenges` with the browser's user agent and IP address, both encrypted. Only passing the challenge deletes the row. Cancelling the sign-in, letting it expire or starting another one keeps it, so nobody can silence the alert by walking away. Passing the first factor again for the same account in the same session keeps the row it already has, so a retry adds no second row and doesn't postpone the alert. A hold from a known device writes no row.

Keystone's scheduled `keystone:sweep-abandoned-challenges` task runs every five minutes, on one server, so run Laravel's scheduler. It records a `challenge.abandoned` event for each row at least 7 minutes old, with the time, IP address and user agent of the sign-in, then deletes the rows. A run never starts while an earlier one is still going, and one that died blocks the next for at most 15 minutes. An account with several such rows gets one alert about all of them, which says how many there were and lists their devices and IP addresses. A mail lists the first five of each, then says how many more there were. The 7 minutes aren't configurable.

## What they say

The default alert is one notification class, `ClaudioDekker\Keystone\Notifications\SecurityAlert`, with a view and translation keys per type. Each mail says what happened and:

- when, in UTC. For abandoned challenges that is when the sign-in passed its first factor. An alert about several gives the time of the earliest;
- the IP address of the request that caused it, and where that address is when the [IP-location port](#ip-location) knows. An alert about several IP addresses lists them and names no place;
- the device, as the platform and browser the [session-info port](#session-info) parses from the user agent, or "Unknown device". The raw user agent never appears in a mail;
- the type of the credential involved, when there is one, such as `passkey`. Never its label: its owner typed that, and a mail client could turn a label that looks like a web address into a link;
- "If this wasn't you, sign in and review your security settings."

Every value is escaped, the mail isn't Markdown, and it carries no links, so a crafted user agent can't add one.

To change the wording, override the keys under `keystone::alerts` in `lang/vendor/keystone/{locale}/alerts.php`. To change anything else, name your own notification (below).

## Changing or silencing an alert

`keystone.notifications` has a slot per type of security event. Each slot names the notification class that type sends, or `null` to send none:

```php
'notifications' => [
    'sessions.terminated' => App\Notifications\SessionsEnded::class,
],
```

Keystone builds your notification with the event and sends it on demand to each recipient's address. Implement `SecurityEventAlertContract`, which makes PHP require a constructor that takes the `SecurityEvent`, followed by the other events the same alert is about. Only `challenge.abandoned` ever passes others:

```php
use Carbon\CarbonImmutable;
use ClaudioDekker\Keystone\Notifications\Contracts\SecurityEventAlertContract;
use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SessionsEnded extends Notification implements SecurityEventAlertContract, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public CarbonImmutable $occurredAt;

    public ?string $ipAddress;

    public function __construct(SecurityEvent $event, SecurityEvent ...$others)
    {
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
    }

    // via() and toMail() as usual
}
```

Copy what you need from the events in the constructor rather than keeping the events themselves: an event isn't stored when writing the audit trail failed, and a queued notification reloads a model it keeps from the database. Implement `ShouldQueue` so the mail doesn't slow the request down, and `ShouldBeEncrypted` so the IP address it carries isn't readable in your `jobs` and `failed_jobs` tables.

A slot may name any type of security event, including one that doesn't alert by default, such as `proof.rejected`. `SecurityAlert` only has a mail for the types in the [table above](#types-that-alert), so a slot for any other type names your own notification.

Set a slot to `null` to silence that type. There is no switch that silences every type at once. Your app refuses to boot on a slot holding any other value, a slot for a type that doesn't exist, a notification that doesn't implement `SecurityEventAlertContract`, or `SecurityAlert` for a type it has no mail for.

## Ports

Two ports tell Keystone more about a request than its raw IP address and user agent. Both are `@api` interfaces you may bind your own class to. Neither may throw; Keystone treats a throw as "unknown" and reports it.

### IP location

`ClaudioDekker\Keystone\IpLocation::locate(string $ipAddress): ?string` names where an IP address is. The default alert looks it up on your queue worker when it builds the mail, so no request waits on it and a slow lookup can't tell anyone whether an account exists.

Without anything installed, the port knows nothing. With [`stevebauman/location`](https://github.com/stevebauman/location) installed, Keystone uses its configured driver and fallbacks and names the city and country. It skips every driver that would send your users' IP addresses over plain `http`, such as that package's default `IpApi`, and tries the next; set `keystone.ip_location.allow_plaintext_driver` to `true` to use them anyway.

### Session info

`ClaudioDekker\Keystone\SessionInfo::describe(string $userAgent): ?Device` names the platform and browser of a user agent. Without anything installed, the port knows nothing and alerts say "Unknown device". With [`matomo/device-detector`](https://github.com/matomo-org/device-detector) installed, Keystone uses it. The alert parses the user agent when it is created, so the queue carries the device's label rather than the user agent.

### Binding your own

Bind either port in a service provider:

```php
use ClaudioDekker\Keystone\IpLocation;
use ClaudioDekker\Keystone\SessionInfo;

$this->app->bind(IpLocation::class, MyIpLocation::class);
$this->app->bind(SessionInfo::class, MySessionInfo::class);
```

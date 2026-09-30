# Security alerts

Keystone mails an account's owner when something happens to their account that they should know about, such as an operator ending every session of it. Each alert is about one [security event](security-events.md), sent by the recorder that records it, so nothing in your app needs to be wired up.

Alerts are queued. Run a queue worker, or they are never sent: `queue.default` set to `sync` sends them inside the request, and production refuses to boot on the `null` queue (see [Configuration](configuration.md#boot-checks)).

## Types that alert

| Type | Alerted when |
|---|---|
| `sessions.terminated` | an operator ended every session of the account, unless they passed `--no-alert` (see [Operator commands](operator-commands.md#ending-sessions)); ending every account's sessions with `--all` alerts nobody |

Later releases add alerts to more types.

## Who gets them

Every verified address of the account gets its own mail. While an account has no verified address, its unverified addresses get one each instead. The addresses are read before the change that caused the event, so an address a change removes still hears about it. Keystone never sends an alert to an address someone typed.

There is no de-duplication: two events send two alerts, however alike.

## What they say

The default alert is one notification class, `ClaudioDekker\Keystone\Notifications\SecurityAlert`, with a view and translation keys per type. Each mail says what happened and:

- when, in UTC;
- the IP address of the request that caused it, and where that address is when the [IP-location port](#ip-location) knows;
- the device, as the platform and browser the [session-info port](#session-info) parses from the user agent, or "Unknown device". The raw user agent never appears in a mail;
- the credential involved, when there is one;
- "If this wasn't you, sign in and review your security settings."

Every value is escaped, the mail isn't Markdown, and it carries no links, so a crafted user agent or credential label can't add one.

To change the wording, override the keys under `keystone::alerts` in `lang/vendor/keystone/{locale}/alerts.php`. To change anything else, name your own notification (below).

## Changing or silencing an alert

`keystone.notifications` has a slot per type of security event. Each slot names the notification class that type sends, or `null` to send none:

```php
'notifications' => [
    'sessions.terminated' => App\Notifications\SessionsEnded::class,
],
```

Keystone builds your notification through the container, passing the event as `$event`, and sends it on demand to each recipient's address:

```php
use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SessionsEnded extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public SecurityEvent $event) {}

    // via() and toMail() as usual
}
```

The event may not be stored, if writing the audit trail failed, so copy what you need from it rather than relying on it being reloaded on the queue. Implement `ShouldQueue` so the mail doesn't slow the request down, and `ShouldBeEncrypted` so the IP address and user agent it carries aren't readable in your `jobs` and `failed_jobs` tables.

Set a slot to `null` to silence that type. There is no switch that silences every type at once, and any other value, or a slot for a type that doesn't exist, stops your app from booting.

## Ports

Two ports tell Keystone more about a request than its raw IP address and user agent. Both are `@api` interfaces you may bind your own class to. Neither may throw; Keystone treats a throw as "unknown" and reports it.

### IP location

`ClaudioDekker\Keystone\IpLocation::locate(string $ipAddress): ?string` names where an IP address is. Keystone looks it up when it records an event, keeps it in the event's encrypted `location` field and shows it in alerts.

Without anything installed, the port knows nothing. With [`stevebauman/location`](https://github.com/stevebauman/location) installed, Keystone uses its configured driver and fallbacks and names the city and country. It skips every driver that would send your users' IP addresses over plain `http`, such as that package's default `IpApi`, and tries the next; set `keystone.ip_location.allow_plaintext_driver` to `true` to use them anyway.

The lookup runs during the request that records the event, including a refused sign-in, so prefer a local database (MaxMind) or a header your proxy sets (Cloudflare) over a remote API.

### Session info

`ClaudioDekker\Keystone\SessionInfo::describe(string $userAgent): ?Device` names the platform and browser of a user agent. Without anything installed, the port knows nothing and alerts say "Unknown device". With [`matomo/device-detector`](https://github.com/matomo-org/device-detector) installed, Keystone uses it.

Bind your own in a service provider:

```php
use ClaudioDekker\Keystone\IpLocation;

$this->app->bind(IpLocation::class, MyIpLocation::class);
```

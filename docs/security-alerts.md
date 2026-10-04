# Security alerts

Keystone mails an account's owner when something happens to their account that they should know about, such as an operator ending every session of it. Each alert is about one [security event](security-events.md), sent by the recorder that records it, so nothing in your app needs to be wired up.

Alerts are queued. Run a queue worker, or they are never sent: `queue.default` set to `sync` sends them inside the request, and production refuses to boot on the `null` queue (see [Configuration](configuration.md#boot-checks)).

## Types that alert

| Type | Alerted when |
|---|---|
| `account.suspended` | an operator suspended the account (see [Operator commands](operator-commands.md#suspending-accounts)) |
| `account.unsuspended` | an operator lifted the account's suspension |
| `credential.added` | a credential was added to the account, such as a second factor at [enrollment](enrollment.md) |
| `recovery_code.used` | a [recovery code](challenge.md#recovery-codes) answered the challenge; the mail says how many codes the account has left |
| `recovery_codes.generated` | a new set of recovery codes replaced the account's codes; a first set alerts nobody |
| `sessions.terminated` | an operator ended every session of the account, unless they passed `--no-alert` (see [Operator commands](operator-commands.md#ending-sessions)); ending every account's sessions with `--all` alerts nobody |

## Who gets them

Every verified address of the account gets its own mail. While an account has no verified address, its unverified addresses get one each instead. The addresses are read before the change that caused the event, so an address a change removes still hears about it.

There is no de-duplication: two events send two alerts, however alike.

## What they say

The default alert is one notification class, `ClaudioDekker\Keystone\Notifications\SecurityAlert`, with a view and translation keys per type. Each mail says what happened and:

- when, in UTC;
- the IP address of the request that caused it, and where that address is when the [IP-location port](#ip-location) knows;
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

Keystone builds your notification with the event and sends it on demand to each recipient's address. Implement `SecurityEventAlertContract`, which makes PHP require a constructor that takes the `SecurityEvent`:

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

    public function __construct(SecurityEvent $event)
    {
        $this->occurredAt = $event->occurred_at;
        $this->ipAddress = $event->ip_address;
    }

    // via() and toMail() as usual
}
```

Copy what you need from the event in the constructor rather than keeping the event itself: it isn't stored when writing the audit trail failed, and a queued notification reloads a model it keeps from the database. Implement `ShouldQueue` so the mail doesn't slow the request down, and `ShouldBeEncrypted` so the IP address it carries isn't readable in your `jobs` and `failed_jobs` tables.

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

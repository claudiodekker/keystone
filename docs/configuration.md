# Configuration

Keystone works without a config file of its own. Every setting has its default in the package's `config/keystone.php`, and the defaults are the strict ones. To change one, publish the file:

```shell
php artisan vendor:publish --tag=keystone-config
```

Your `config/keystone.php` is merged over Keystone's, key by key. A setting that holds named keys, such as `rate_limits.requests_per_minute`, keeps Keystone's default for every key you leave out, so a setting added in a later release gets its default even though your file predates it. A list, such as `clear_site_data`, replaces Keystone's whole: `['cache']` clears only the cache, and `[]` clears nothing. You may delete every setting you don't change.

## Settings

| Setting | Default | What loosening it costs |
|---|---|---|
| `methods` | `null`: every installed credential type, on every surface it serves | Nothing: it can only narrow what the installed types offer. See [Methods](#methods). |
| `session.absolute_lifetime_seconds` | `43200` (12 hours) | A stolen session that is kept busy stays signed in longer. `null` lets it live forever. See [Session lifetime](#session-lifetime). |
| `rate_limits.requests_per_minute.view` | `60` | Faster scripted probing of Keystone's pages per IP address and account. |
| `rate_limits.requests_per_minute.start` / `.submit` / `.change` | `10` each | Faster scripted submissions per IP address and account. |
| `rate_limits.failed_attempts_per_hour` | `20` | More online guesses at each account's credentials. See [Rate limiting](rate-limiting.md). |
| `events.enabled` | `true` | `false` records nothing: no log line, no audit trail, no alert, no `SecurityEventRecorded`. See [Security events](security-events.md). |
| `log_channel` | `null`: your default channel | Nothing. |
| `notifications.<type>` | `SecurityAlert` for each type that alerts | `null` silences that type's alert, so its owner never hears of it. See [Security alerts](security-alerts.md#changing-or-silencing-an-alert). |
| `ip_location.allow_plaintext_driver` | `false` | Your users' IP addresses cross the network in plain text to a location API. See [IP location](security-alerts.md#ip-location). |
| `hardening.frame_ancestors` | `[]`: no page may frame Keystone's | Each listed source can overlay Keystone's pages and hide what users click. See [Hardening](hardening.md#framing). |
| `trusted_origins` | `[]`: only your app's own origin | Any page on a listed origin can submit to Keystone as your users. See [Hardening](hardening.md#cross-site-requests). |
| `clear_site_data` | `['cache', 'storage']` | Whatever you leave out survives sign-out on a shared computer. See [Hardening](hardening.md#clearing-site-data). |

Every limit is a whole number of at least 1. No setting takes a `0` or `null` that turns a control off without saying so; the `null`s that turn a control off are `session.absolute_lifetime_seconds`'s and each alert slot's, which silences only its own type.

## Methods

`methods` is an allow-list of credential types. Leave it `null` to offer every type you installed wherever it can be used. List a type by name to offer it on every surface it serves, or map its name to the surfaces you want it on:

```php
'methods' => [
    'password',
    'passkey' => ['sign-in'],
],
```

A type you don't list isn't offered anywhere, and a listed surface only narrows what the type serves: listing a type on a surface it doesn't serve stops Keystone from booting. The surfaces are `sign-in`, `challenge`, `registration` and `enrollment`.

## Session lifetime

A session ends in two ways, whichever comes first:

- **Idle:** Laravel's own `session.lifetime` (in minutes) ends a session nobody used for that long. Every request starts the count again.
- **Absolute:** `session.absolute_lifetime_seconds` ends a signed-in session that long after the sign-in, however busy it was. Nothing extends it. A session whose sign-in time is missing or in the future counts as expired.

Keystone checks the absolute lifetime whenever something asks who is signed in, so it needs no middleware of yours. When a session has expired, Keystone ends it, records `session.ended` with the reason `expired`, and sends `Clear-Site-Data`. When your `auth` middleware then refuses the request:

- a browser is redirected to sign in, or wherever your `redirectGuestsTo` sends guests, and the sign-in page's `status` reads "Your session has expired. Please sign in again.";
- a request expecting JSON gets a `401` with `{"message": "Your session has expired. Please sign in again.", "reason": "expired"}`.

The message is `keystone::messages.status.session-expired`, so you can translate it in `lang/vendor/keystone`.

To answer an expired session your own way, bind your own `ClaudioDekker\Keystone\Actions\RespondToExpiredSession` in a service provider and override its `handle()`:

```php
use ClaudioDekker\Keystone\Actions\RespondToExpiredSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

$this->app->bind(RespondToExpiredSession::class, fn () => new class extends RespondToExpiredSession
{
    public function handle(Request $request, AuthenticationException $e): Response
    {
        return response()->json(['reason' => 'expired', 'sign_in' => route('login')], 401);
    }
});
```

The session has already ended by then, whatever you respond. If you change the response, redefine `assertExpiredSessionRefused()` in your `Tests\Keystone\Assertions\SessionExpiryAssertions` trait so Keystone's AppTests check your response instead.

## Boot checks

Keystone checks its configuration every time your app boots, console commands included, and refuses to boot with one `ClaudioDekker\Keystone\Misconfigured` exception that lists every problem it found. Its `failures` property holds the same list.

In every environment, it refuses:

- a setting above of the wrong type, or a limit below 1;
- a `methods` entry that isn't a type name or a type name mapped to a list of surfaces, a type listed twice, a type no installed package registers, or a surface the type doesn't serve;
- two installed packages registering credential types with one name, or a type named `recovery-code`, which Keystone keeps for recovery codes;
- a `log_channel` that isn't one of your `logging.channels`;
- a `notifications` slot for a type that isn't a security event, or holding anything but `null` or a notification class;
- a user model on another database connection than your default one, which Keystone's tables are migrated on.

In production it also refuses what's fine on a laptop but unsafe on a server:

| Setting | Refused |
|---|---|
| your default guard's driver | anything but `keystone` |
| `methods` | no listed type serves sign-in |
| `session.secure`, `session.http_only` | anything but `true` |
| `session.same_site` | anything but `lax` or `strict` |
| `session.cookie` | a name without the `__Host-` prefix, or `__Secure-` when `session.domain` is set |
| `session.path` | anything but `/` |
| `cache.default` | a store using the `array`, `null` or `session` driver, which forget their entries |
| `cache.limiter` (else `cache.default`) | a store using the `file`, `storage`, `array`, `null` or `session` driver, which can't count atomically |
| `app.url` | anything but `https://` |
| `mail.default` | a mailer using the `log` or `array` transport |
| `queue.default` | a connection using the `null` driver |

The installer sets the session cookie up this way. Because the checks run on every boot, a build step that boots your app with `APP_ENV=production` needs the production settings too. The commands that clear or rebuild a cached config (`config:clear`, `config:cache`, `optimize:clear` and `package:discover`) skip the checks, so you can always recover from a cached config Keystone refuses.

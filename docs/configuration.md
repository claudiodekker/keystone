# Configuration

Keystone's config is `config/keystone.php`. Your app's file merges over core's all the way down: maps merge key by key, so a key you leave out keeps its default, and so does a key a later release adds. A list in your file replaces core's list whole, so you can shorten it or empty it. Laravel's cached config is used as cached.

```php
return [
    'rate_limits' => [
        'requests_per_minute' => [
            'view' => 120,
        ],
    ],
    'clear_site_data' => ['cache', 'cookies', 'storage'],
];
```

This file raises the view allowance and keeps every other allowance at its default. It lists three kinds of site data to clear, where core lists two.

| Key | Default | Loosening it |
|---|---|---|
| `clear_site_data` | `['cache', 'storage']` | A shorter list leaves more of an ended session in the browser, and `[]` leaves all of it. Adding `cookies` signs the browser out of everything it holds a cookie for on your domain. |
| `events.enabled` | `true` | `false` keeps no trail and sends no alert. |
| `hardening.frame_ancestors` | `[]` | A listed source can frame Keystone's pages and trick a user into clicking on them. |
| `log_channel` | `null` | None. It only picks where the security event lines go. |
| `methods` | `null` | None. It only narrows: a type it leaves out is offered nowhere, and it can't offer a type on a surface the type doesn't serve. |
| `rate_limits.failed_attempts_per_hour` | `20` | A higher number gives an attacker more guesses per account. |
| `rate_limits.requests_per_minute.change` | `10` | A higher number lets more sign-outs through from one address. |
| `rate_limits.requests_per_minute.submit` | `10` | A higher number lets more sign-in submits through from one address. |
| `rate_limits.requests_per_minute.view` | `60` | A higher number lets more page views through from one address. |
| `session.absolute_lifetime_seconds` | `12 * 60 * 60` | A longer lifetime keeps a stolen session alive longer while it stays busy, and `null` keeps it alive for as long as it stays busy. |
| `trusted_origins` | `[]` | Any page on a listed origin can sign users in and out. |

`Cross-Origin-Opener-Policy` and `base-uri` have no setting. The [security page](security.md) says what each key does.

## Choosing the methods

`methods` is `null` by default, which offers every installed credential type on every surface it serves. Set a list to offer fewer. A bare name keeps all of a type's surfaces, and a name with a list of surfaces narrows it to those:

```php
'methods' => [
    'password' => ['registration', 'sign-in'],
],
```

The surfaces are `challenge`, `enrollment`, `registration` and `sign-in`. This list offers the password on registration and sign-in and nowhere else. A type the list leaves out is not on the sign-in page, and a proof submitted to it is answered as not found.

## How long a session lasts

Two timers end a session. Laravel's `session.lifetime`, in minutes in `config/session.php`, ends a session that sits idle, and every request restarts it. Keystone's `session.absolute_lifetime_seconds` ends a session a fixed time after its sign-in, and nothing restarts it:

```php
// config/keystone.php
'session' => [
    'absolute_lifetime_seconds' => 8 * 60 * 60,
],
```

With this file, a user who signs in at 9:00 is signed out on the first request after 17:00 that asks who is signed in, however busy they kept the session. A user who stops using the app is signed out by `session.lifetime` first. `null` turns the absolute lifetime off, and `0` is refused at boot. Keystone stamps the sign-in time whether or not the lifetime is on, so turning it on later counts from each session's own sign-in.

When the absolute lifetime has passed, Keystone ends the session, records `session.ended` with the reason `keystone.expired` and clears the browser's site data, before your code sees the user. From then on the request is a guest's:

- A page behind the `auth` middleware sends the user where Laravel sends guests. `redirectGuestsTo()` still decides where that is, and the page they wanted is kept, so signing in takes them back to it. The sign-in page then says "Your session has expired. Please sign in again."
- A JSON request to such a page gets a `401` with `{"message": "Your session has expired. Please sign in again.", "reason": "expired"}`, so a client can tell an expiry from never having signed in. A request counts as JSON when `expectsJson()` says so.
- A page anyone can see shows as it does to a guest. The next sign-in page the user opens says why they were signed out.

To answer differently, bind your own response in a service provider:

```php
use App\Http\Responses\SessionExpired;
use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;

public function register(): void
{
    $this->app->bind(SessionExpiredResponse::class, SessionExpired::class);
}
```

```php
namespace App\Http\Responses;

use ClaudioDekker\Keystone\Contracts\SessionExpiredResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionExpired implements SessionExpiredResponse
{
    public function toResponse(Request $request, AuthenticationException $exception): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        return to_route('welcome');
    }
}
```

Keystone has already ended and recorded the session by the time your response runs, so your response decides only what the user sees. It runs when a request needs a signed-in user and its session expired during that request. Keystone adds it through Laravel's exception handler, so an app whose handler doesn't extend `Illuminate\Foundation\Exceptions\Handler` gets its own unauthenticated response instead. A session whose sign-in time lies in the future counts as expired, so servers behind one load balancer need synchronised clocks.

## What Keystone refuses

Keystone checks its config when you cache it, and on every boot of an app that has no cached config. It refuses to boot an app that fails a check, and its one exception lists every failure, so one deploy fixes them all. A config that fails can't be cached: `config:cache` and `optimize` stop with the exception and leave no cached config behind. `config:clear` and `optimize:clear` run without the checks, so a refused config can always be cleared.

An app that runs from a cached config pays nothing for the checks per request, so run `config:cache` (or `optimize`) in your deploy, as Laravel recommends. Your test suite boots without a cached config, so it runs the checks on every test boot, and your CI catches a change to your bindings or middleware that fails one.

The checks set no ceilings. In every environment, they refuse a value of the wrong type, and a `0` or `null` that would switch a control off, except the `null` that turns off the absolute session lifetime:

| Key | Refused unless it is |
|---|---|
| `rate_limits.failed_attempts_per_hour` and every `rate_limits.requests_per_minute.*` | An integer of at least 1. |
| `events.enabled` | `true` or `false`. |
| `log_channel` | `null`, or the name of a channel in your `logging` config. |
| `clear_site_data` | A list of `cache`, `cookies`, `executionContexts` and `storage`. |
| `hardening.frame_ancestors` | A list of single CSP sources, such as `'self'` or `https://portal.example.com`. |
| `methods` | `null`, or a list of installed types by name, each alone or with a non-empty list of surfaces the type serves. No type may be named twice. |
| `session.absolute_lifetime_seconds` | `null`, or an integer of at least 1. |
| `trusted_origins` | A list of origins, each a scheme and a host with nothing after it. |

It also refuses two packages that register one credential type name, and a user model on a database connection other than the default one, because Keystone's tables are migrated on the default connection.

Keystone also refuses to boot an app whose HTTP kernel doesn't run Keystone's global middleware that captures the request context. Without it, Keystone can't read the client's address, so every request would count as coming from the same address.

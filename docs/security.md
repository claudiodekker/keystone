# Security

## Rate limits

Keystone has one rate limiter with two limits. Every Keystone endpoint takes the request limit first, before validation runs. Every sign-in attempt then takes the failed-attempt limit before the credential type verifies anything. Methods and controllers never see a key, a count or an allowance.

### The request limit

Each endpoint is one kind of step, and each kind has its own allowance a minute, counted per IP address and, separately, per signed-in account.

| Step kind | Endpoints | Allowance |
|---|---|---|
| `view` | The sign-in page | 60 a minute |
| `submit` | The sign-in submit | 10 a minute |
| `change` | Signing out | 10 a minute |

An IPv4 address counts on its own. An IPv6 address counts together with every other address in its /64, and an IPv4-mapped IPv6 address counts as the IPv4 address it wraps. Every request without a usable address shares one count, so a broken proxy setup throttles those requests together rather than not at all.

The limit is middleware the controller declares through `HasMiddleware`, so it still runs when your app's controller replaces an action outright.

### The failed-attempt limit

An account gets 20 failed attempts an hour per credential type and flow. The attempt is counted before the type verifies anything, so wrong answers sent at the same time never get more verify calls than the allowance. The count is given back only when the attempt signs in or the type throws, and only inside the hour it was counted in. A refused attempt stays counted, a proof the type accepts for an account Keystone refuses stays counted, and nothing ever resets a count: counts only expire.

A typed address that names no account is counted under the address itself, normalized the way the account lookup compares it. A made-up address locks exactly like a real one, and two spellings share a count exactly when they would name the same account.

### Configuration

```php
'rate_limits' => [
    'failed_attempts_per_hour' => 20,
    'requests_per_minute' => [
        'change' => 10,
        'submit' => 10,
        'view' => 60,
    ],
],
```

Every allowance lives under `rate_limits`. Loosen or tighten any of them there. The windows are fixed.

### The refusal

A spent limit refuses with status 429, a `Retry-After` header holding the seconds until the latest spent count expires, at least 1, and the message "Too many attempts. Please try again in :seconds seconds." The refusal is the same for a real account, a made-up one and a credential type Keystone doesn't know, so it tells nothing about who exists.

The refusal is `ClaudioDekker\Keystone\Exceptions\ThrottledException`, which your app's exception handler renders. Without a render callback, Laravel's default 429 page answers an HTML request and the message answers a JSON request. Register a callback to render it your own way, keeping its headers so the `Retry-After` reaches the client:

```php
use ClaudioDekker\Keystone\Exceptions\ThrottledException;
use Illuminate\Foundation\Configuration\Exceptions;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (ThrottledException $e) {
        return response()->view('errors.throttled', ['message' => $e->getMessage()], 429, $e->getHeaders());
    });
})
```

The exception is never reported.

### The store

Counts live in Laravel's rate-limiter cache store: the store `cache.limiter` names, else the default cache store. Use a store that persists, doesn't evict and increments atomically, such as Redis or the database. When the store fails, the failed-attempt limit refuses every attempt and the request limit lets every request through, and the failure is reported to your exception handler either way.

Every key is an HMAC-SHA256 of its parts under a key derived from your app key, so the store never holds an address or an identifier in clear. Rotating `APP_KEY` starts every count over.

### When a limit trips

The first refused attempt of each count's window records a `limit.tripped` security event, for the named or signed-in account when there is one, with a reason naming the limit: `keystone.failed_attempt_limit` or `keystone.request_limit`. The same trip dispatches Laravel's `Illuminate\Auth\Events\Lockout` event, so a listener written for Fortify keeps working. A listener that fails is reported, and the refusal stands.

### Testing

Keystone's AppTests count limits in a fresh in-memory store for each test, so the store your app limits in carries no counts from one test to the next. They read every allowance from your live config through `failedAttemptAllowance()` and `requestAllowance()`, so an app that tunes a limit still passes. A throttled answer is checked through `assertSignInThrottled()` and `assertSignOutThrottled()`. Redefine them in your assertion traits when your app renders a throttled refusal differently.

## Hardening

Keystone sets the same headers on every response of its routes, refuses a cross-site request that would change something, and tells the browser to clear its site data when a session ends. None of it needs setup. Three config keys loosen it where a real setup needs that.

### Which routes

A route is Keystone's when its controller is one of Keystone's controllers or your subclass of one. Overriding an action, or adding a method, changes nothing about that. Your other routes keep their own headers and get no cross-site check from Keystone.

### The hardening floor

Every response of a Keystone route carries these headers, whatever your app set, and a redirect, a throttled refusal, a rendered exception and a response from another middleware carry them too:

| Header | Value |
|---|---|
| `Cache-Control` | `no-store, max-age=0, must-revalidate` |
| `Pragma` | `no-cache` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `X-Frame-Options` | `DENY` |

Symfony adds `private` to the `Cache-Control` value when it sends the response.

The `Content-Security-Policy` merges by directive instead. The directives your app set stay. Keystone sets `object-src`, `base-uri` and `frame-ancestors` to `'none'` in each policy the response carries, so an app policy with `frame-ancestors *` doesn't loosen it. A response with no policy gets one holding just those three directives. Keystone never writes `script-src`, so your app owns what scripts may run. A `Content-Security-Policy-Report-Only` header stays as it is.

An app policy sent as several headers, or as one header with comma-separated policies, is merged policy by policy. A directive repeated in one policy keeps its first value, as a browser reads it.

### Framing by another site

Listing the sources that may frame Keystone's pages in `hardening.frame_ancestors` lets them, for a white-label host that shows your sign-in page in a frame:

```php
'hardening' => [
    'frame_ancestors' => ['https://portal.example.com'],
],
```

Each entry is one CSP source: a host, a scheme or `*`. `frame-ancestors` then lists them instead of `'none'`, and `X-Frame-Options` is dropped, since it can't name a source. A listed source can frame the sign-in page and trick a user into clicking on it, so list only sources you control. Keystone refuses to boot when the value isn't a list of single sources.

### Cross-site requests

A request that is not `GET`, `HEAD` or `OPTIONS` to a Keystone route must come from your app's own pages. It passes when any of these holds:

- `Sec-Fetch-Site` is `same-origin`.
- It carries the session's CSRF token in `_token`, `X-CSRF-TOKEN` or the encrypted `X-XSRF-TOKEN`.
- Its `Origin` is the request's own origin or one in `trusted_origins`.

Anything else, a `same-site` request from another subdomain included, is refused with Laravel's `TokenMismatchException`, which your app renders as its usual 419 page. The check runs in the `web` group after the session starts, and after Laravel's own CSRF check. It reads none of the exemptions you gave Laravel's, so listing a Keystone route there doesn't open it.

Each refusal records a `request.rejected` security event with the reason `keystone.cross_site_request`. The event is anonymous: a page on any site can make a browser send a request as the user, so the refusal is never recorded against the user's account. It is a log line, and identical ones are logged once per 60 seconds.

Another origin of your own app can submit to Keystone's routes once you list it:

```php
'trusted_origins' => ['https://admin.example.com'],
```

Each entry is a scheme and a host, with a port if it has one, and nothing after it. Any page on a listed origin can then sign users in and out. Keystone refuses to boot when the value isn't a list of origins.

### Clearing site data

When Keystone ends a session, the response to that request carries `Clear-Site-Data`, whatever route the request was for. Signing out does, and so does your own code calling `Auth::logout()`. The browser clears what `clear_site_data` lists:

```php
'clear_site_data' => ['cache', 'storage'],
```

The kinds are `cache`, `cookies`, `storage` and `executionContexts`. Clearing `cookies` signs the browser out of everything it holds a cookie for on your domain. An empty list sends no header. Keystone refuses to boot when the value isn't a list of those kinds.

### Testing

Keystone's AppTests send `Sec-Fetch-Site: same-origin` with every request, as a browser does from your app's own pages. Laravel skips its own CSRF check in tests, but Keystone's never does. `fromAnotherSite()` sends the requests after it as a page on another site would. A refusal is checked through `assertCrossSiteRefused()`, which expects a 419. Redefine it in your assertion traits when your app renders that refusal differently.

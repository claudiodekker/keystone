# Rate limiting

Keystone rate limits its own endpoints. Nothing in your routes, middleware or controllers needs to be wired up, and a spent limit always refuses the same way: a `429 Too Many Requests` response with a `Retry-After` header, reading "Too many attempts. Please try again in :seconds seconds."

## Limits

| Limit | Counted per | Allowance |
|---|---|---|
| Request limit | IP address, and separately the signed-in account, per kind of step | 60 page views, 10 submissions or sign-outs a minute |
| Failed-attempt limit | account and credential type in each flow, from any IP address | 20 wrong answers an hour |

The request limit is checked before anything else, so even invalid input or an unknown credential type is throttled. It is taken before your controller's action runs, so it still applies when you replace an action such as `show()` or `store()` in your published controller. A wrong answer counts against the account the typed identifier names; an identifier that names no account gets a bucket of its own, so a made-up address locks exactly like a real one.

A successful sign-in doesn't count, but nothing ever resets a count: counts only expire at the end of their window.

The first refusal in each window records a `limit.tripped` [security event](security-events.md), about the account when one is named, and dispatches Laravel's `Illuminate\Auth\Events\Lockout` with the request, as Fortify does. Listen for either to react to lockouts. Unlike Fortify, `Lockout` fires once per window, not on every throttled request.

## The store

Counts live in Laravel's rate-limiter cache store: the store named by `cache.limiter`, else your default cache store. It must increment atomically and must not evict entries early, so use `redis`, `database` or `memcached` in production. Keys are hashed with your app key, and no email address, username or IP address is stored in clear.

If the store fails, Keystone reports the exception and refuses every sign-in attempt until it is back. Page views and other requests go through.

## Changing the response

The refusal is a 429 with a `Retry-After` header and one message. Change the words by publishing the `keystone::messages.throttled` translation. Keystone takes the request limit as controller middleware, so replacing `show()` or `store()` in your published controller doesn't drop it.

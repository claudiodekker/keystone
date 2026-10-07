# Rate limiting

Keystone rate limits its own endpoints. Nothing in your routes, middleware or controllers needs to be wired up, and a spent limit always refuses the same way: a `429 Too Many Requests` response with a `Retry-After` header, reading "Too many attempts. Please try again in :seconds seconds." Laravel never reports the refusal, so spending a limit adds nothing to your logs or error tracker.

## Limits

| Limit | Counted per | Allowance |
|---|---|---|
| Request limit | IP address, and separately the account the session names (signed in, or held at the challenge or enrollment), per kind of step | 60 page views, 10 submissions or sign-outs a minute |
| Failed-attempt limit | account and credential type in each flow, from any IP address; each of the account's known devices counts apart from every other browser | 20 wrong answers an hour |
| Shared TOTP limit | account, for TOTP codes in the challenge and the [sudo replay](sudo.md#the-replay) together; each known device counts apart | 20 wrong codes an hour, and at most 100 in 24 hours |

Change the allowances in `keystone.rate_limits` (see [Configuration](configuration.md)). Each must be a whole number of at least 1: no value turns a limit off. Keystone's AppTests read the same allowances, so they check the limits you set.

A six-digit code is easy enough to guess that wrong [TOTP](totp.md) codes share one count across every flow behind a first factor, so moving between the challenge and the sudo replay gets an attacker no fresh guesses. The hourly allowance is `failed_attempts_per_hour`; the 100-a-day ceiling is fixed, and counts only the wrong codes the hourly limit let through, so hammering a spent hour can't lock the account out for the day. Every other type counts per flow: wrong answers at the challenge never touch the first factor's count, wrong answers at the sudo replay count in the `sudo` flow apart from sign-in's, and cancelling a challenge resets nothing.

The request limit is checked before anything else, so even invalid input or an unknown credential type is throttled. It is taken before your controller's action runs, so it still applies when you replace an action such as `show()` or `store()` in your published controller. A wrong answer counts against the account the typed identifier names; an identifier that names no account gets a bucket of its own. Spellings that Keystone [matches as one address](installation.md#email-addresses) share a bucket, as they would share a real account: `Nobody@Example.com` and `nobody@example.com` share one, while `rené@example.com` and `rene@example.com` get one each. A made-up address locks exactly like a real one.

A successful sign-in doesn't count, but nothing ever resets a count: counts only expire at the end of their window.

The first refusal in each window records a `limit.tripped` [security event](security-events.md), about the account when one is named, and dispatches Laravel's `Illuminate\Auth\Events\Lockout` with the request, as Fortify does. Listen for either to react to lockouts. Unlike Fortify, `Lockout` fires once per window, not on every throttled request. A failed-attempt trip also [alerts the account's owner](security-alerts.md), once per count and window; a request-limit trip never alerts.

## Locking an account's owner out

The failed-attempt limit counts per account and ignores the IP address, so someone other than the owner can spend it. Anyone who knows an account's address can send 20 wrong passwords for it, from one IP address or many. Keystone then refuses every password sign-in for that account from a browser that isn't one of its [known devices](security-alerts.md#new-devices), the owner's correct password included, until the count expires an hour after the first wrong answer. The attacker can repeat this when the window ends. Counting by account is what holds a guesser who rotates through IP addresses to 20 guesses an hour, and Keystone makes this trade rather than count per address.

Each known device of the account counts its own wrong answers, with its own allowance, so failures from other browsers never lock the owner out of a browser they signed in from before. A known device earns nothing else: it is challenged, alerted about and limited like any other browser, and its own wrong answers lock only itself. A device cookie copied from the owner's browser stops counting apart at the owner's next sign-in, when the browser gets a new value. The count belongs to the device, not to the cookie's value, so a sign-in that hands the browser a new value leaves its spent allowance spent.

The lock is narrow. It refuses only the flow whose count is spent, sign-in, the challenge or the [sudo replay](sudo.md#the-replay), so sessions that are already signed in carry on, and a spent sign-in count doesn't keep a signed-in owner from earning sudo. Each credential type counts apart, so locking an account's passwords leaves its other first-factor types alone. The refusal's `Retry-After` header says when the count expires.

Nothing ends a lock early. A count is only reset by expiring, and no [operator command](operator-commands.md) touches one: they end sessions and suspend accounts. Raising `rate_limits.failed_attempts_per_hour` makes a lock cost more wrong passwords, and gives every guesser the same number more.

The first trip of each count in a window mails the account's owner a `limit.tripped` alert, so they hear that someone is guessing. To silence it, set the `'limit.tripped'` slot in `keystone.notifications` to `null`. The event's reason is `keystone.failed_attempt_limit`, and Laravel's `Lockout` event fires with it.

## Sharing an IP address

The request limit counts per IP address, so everyone behind one address shares it, such as an office or a school behind one NAT address. Sign-ins, challenge answers, recovery-code saves, enrollment answers and sudo answers all count as submissions. With the default of 10 a minute and a second factor required, one address completes at most five sign-ins a minute. An IPv6 address counts as its /64 network. If your users sit behind shared addresses, raise `rate_limits.requests_per_minute.submit`. That also gives a scripted client more submissions from each address.

## The store

Counts live in Laravel's rate-limiter cache store: the store named by `cache.limiter`, else your default cache store. It must increment atomically and must not evict entries early, so use `redis`, `database` or `memcached` in production. In production, Keystone refuses to boot when that store uses the `file`, `storage`, `array`, `null` or `session` driver. Keys are hashed with your app key, and no email address, username or IP address is stored in clear.

If the store fails, Keystone reports the exception and refuses every sign-in attempt until it is back. Page views and other requests go through.

## Changing the response

The refusal is a 429 with a `Retry-After` header and one message. Change the words by publishing the `keystone::messages.throttled` translation. Keystone takes the request limit as controller middleware, so replacing `show()` or `store()` in your published controller doesn't drop it.

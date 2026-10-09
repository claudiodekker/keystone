# Registration

A visitor creates an account by proving they read the inbox of the address they type. They type it on the register page, Keystone mails it a link, and opening that link and choosing Continue starts the registration in their browser, on the finish page.

## When registration is open

Registration is open while at least one credential type listed in `keystone.methods` serves the `registration` surface, as the password does. With [keystone-password](password.md) installed and `keystone.methods` left at `null`, it is open.

While no listed type serves it, every registration step, its form submissions included, sends the user to the sign-in page, which reads "Registration is not available." Nothing is validated, looked up or mailed. To close registration, narrow each type to the surfaces you keep:

```php
'methods' => [
    'password' => ['sign-in', 'enrollment'],
    'totp',
],
```

The Inertia-Vue sign-in page links to the register page only while registration is open.

## Asking for a link

The register page (`register`) asks for an email address. Its form posts to `register.submit`, which requires an address of at most 255 characters that a strict reading of RFC 5322 accepts, refusing quoted local parts, comments and IP addresses in place of a domain, and stores it the way Keystone [stores every address](installation.md#email-addresses). Invalid input goes back to the register page with its errors, and only the address is flashed back.

A valid address always sends the user on to the "check your email" step (`register.link-sent`). What happens behind it depends on who holds the address:

- When no active account holds it, Keystone mails it a registration link.
- When an active account holds it verified, or holds it unverified while holding no verified address, Keystone mails nothing to it. It records `address.claim_attempted` on that account, with flow `registration`, and [alerts its owner](security-alerts.md) that someone tried to sign up with their address. A suspended account counts as active here.
- When only a deleted or invalidated account holds it, it is free: the link is mailed and nobody is alerted.

The response is the same in every case, so the page can't tell anyone whether an address has an account. The whole step takes at least 300 ms whatever happens, and the session isn't written to. The mail or alert is queued, so this holds only while a queue worker sends it. With `queue.default` set to `sync`, the mail is sent inside the request, and the time it takes can tell a free address from a taken one.

### The delivery limit

Each address gets at most 3 registration mails in 10 minutes, links and alerts together, counted against the typed address whether or not an account holds it. Past that, the step answers exactly as before and mails nothing. The first refusal in a window records `limit.tripped` with the reason `keystone.delivery_limit`. Change the allowance with `keystone.rate_limits.deliveries_per_ten_minutes` (see [Rate limiting](rate-limiting.md#limits)). While the rate limiter's store is down, the step mails nothing and reports the failure.

## The link

The link points at your `app.url`, path included, whatever host the request that asked for it came in on, and works for 10 minutes. It carries the address encrypted, so the address never shows in the URL, in your server logs or in the browser's history.

Opening the link (`register.verify`) shows a page with one button, Continue. Opening it changes nothing, so a mail scanner that follows the link spends nothing, and the page can be opened again. The button posts to `register.verify.consume`, at the same URL, which spends the link: it works once, and every later use is refused. Once spent, the session gets a new id, any pending sign-in, sudo or ceremony is dropped, and the address is kept as proven for 30 minutes. The user is sent on to the finish page.

A link that no longer works sends the user to the "link expired" step (`register.link-expired`), with no reason given. That covers a link that expired, was already used, was changed, was signed before your `APP_KEY` was rotated, or came in on another host than `app.url`'s, and an address an active account came to hold since the link was mailed. Opening or spending such a link records `request.rejected` with a reason saying which check failed (see [Security events](security-events.md#types)), still without spending it or changing the session. When the address is one an active account came to hold, spending the link also records `address.claim_attempted` on that account and alerts its owner, as asking for a link would. Every response at the link's URL sends `Referrer-Policy: no-referrer`, a refusal or a throttled request included (see [Hardening](hardening.md#headers)).

Links are signed and encrypted with keys derived from your current `APP_KEY` alone, never from `APP_PREVIOUS_KEYS`, so rotating the key ends every link in flight. Keystone keeps a digest of each spent link in `used_email_links` until it expires. Its scheduled `keystone:prune-used-email-links` task deletes the expired ones every hour, on one server, so run Laravel's scheduler.

A link needs a working mailer and queue worker, and production refuses to boot without an `https` `app.url` (see [Configuration](configuration.md#boot-checks)). Behind a proxy, configure your trusted proxies, so the request's scheme, host and path match `app.url`'s. Otherwise every link is refused.

## The finish page

The finish page (`register.finish`) shows the proven address and the credential types that serve registration. A session that hasn't spent a link, or spent it more than 30 minutes ago, is sent back to the register page.

## Signed-in users

A signed-in user who opens any registration step is sent to `/`, and a link they post is not spent.

## Changing the responses

The adapter publishes three controllers, with one hook per outcome:

- `RegistrationController`: `sendRegistrationPage`, `sendRegistrationLinkSent` and `sendRegistrationLinkSentPage`;
- `RegistrationLinkController`: `sendRegistrationLinkPage`, `sendRegistrationLinkConsumed`, `sendRegistrationLinkExpired` and `sendRegistrationLinkExpiredPage`;
- `RegistrationFinishController`: `sendRegistrationFinishPage`.

The link's page renders `auth/EmailedLink`, a page with one button that posts to the `action` it is given. Later emailed links reuse it. If you change a hook, redefine its assertion in `tests/Keystone/Assertions/RegistrationAssertions.php`, `RegistrationLinkAssertions.php` or `RegistrationFinishAssertions.php`, so Keystone's AppTests check your response instead.

To change the mail's wording, override the keys under `keystone::mail.links.registration` in `lang/vendor/keystone/{locale}/mail.php`.

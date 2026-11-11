# Remember me

A user who ticks "Remember me" when signing in comes back signed in on that browser, without proving a factor again, until a fixed time after that sign-in. Signing out on the browser, or anything that ends every session of the account, stops it sooner.

## The box

The sign-in page value carries `remember`, which is `true` while remember me is on. The Inertia and Vue sign-in form shows a "Remember me" checkbox then, and posts it as `remember` with the rest of the form. Your own page can do the same:

```vue
<label v-if="remember" for="remember">
  <input id="remember" name="remember" type="checkbox" value="1" />
  Remember me
</label>
```

Keystone reads `remember` as Laravel's `$request->boolean()` does, so `1`, `true`, `on` and `yes` tick it. A sign-in held at the [challenge](challenge.md) or at [enrollment](enrollment.md) keeps the tick, and the step that completes the sign-in remembers the browser.

## How long it lasts

```php
'remember' => [
    'lifetime_seconds' => 60 * 60 * 24 * 30,
],
```

The lifetime counts from the sign-in that ticked the box. Keystone writes the expiry on the token when it issues it and checks it on the server, and a return writes nothing, so coming back every day buys no extra day, and raising the setting later doesn't lengthen a cookie a browser already holds.

`0` turns remember me off. The box is hidden, a posted `remember` is ignored, and a cookie issued earlier is neither read nor deleted, so turning the lifetime back on lets it restore again until its own expiry. To end those cookies at once, end the accounts' sessions with [`keystone:end-sessions`](operator-commands.md#ending-sessions).

## The cookie

A ticked sign-in hands the browser `__Host-keystone_remember`: 256 random bits in hex, with no account id in it. The cookie is host-only, `Secure`, `HttpOnly` and `SameSite=Lax`, lives until the token's expiry, and is not encrypted by Laravel, since Keystone only compares its digest. The `user_remember_tokens` table keeps one row per remembered browser with the account, the SHA-256 digest of the value, the account's credential epoch at the sign-in and the expiry. The session keeps the id of the row it issued or restored from.

## Coming back

A request from a browser with no signed-in session that sends a live cookie is signed in again as the cookie's account, on a new session id. This is a full sign-in, so the user reaches every page they could before. Keystone records `signed_in` with the reason `keystone.remembered`, fires Laravel's `Login` event with `remember` set to `true`, and `Auth::viaRemember()` answers `true` for that request.

A restored session gets an [absolute lifetime](configuration.md#how-long-a-session-lasts) of its own, counted from the restore. When that lifetime passes and the cookie is still live, the next request restores the session again, with no `session.ended`, no `Clear-Site-Data` and no expiry notice. The user notices nothing.

A return reads the [device cookie](security-events.md#new-devices) to record whether the browser is a known device of the account, and changes neither cookie. A browser that reopens with several tabs sends several requests at once, and each of them restores. A copy of the remember-me cookie used in another browser alerts the owner as a sign-in from a new device. A copy of every cookie the browser holds restores without an alert until the owner's next real sign-in from that browser, as a stolen session cookie would.

Keystone restores once Laravel has started the session, so a global middleware that asks who is signed in before `StartSession` runs sees a guest.

## When it stops working

A cookie dies when:

- its lifetime passes;
- the user signs out on that browser, which deletes only that browser's token, so the account's other remembered browsers keep working;
- the account's credential epoch moves, such as when an operator [ends its sessions](operator-commands.md#ending-sessions);
- the account is [suspended](operator-commands.md#suspending-accounts);
- the session it signed in is [demoted](enrollment.md#signed-in-sessions-that-newly-owe), which deletes that session's token.

A browser that presents a dead cookie, or one no token matches, is a guest. Keystone deletes the token and the cookie, and records `request.rejected` with the reason `keystone.dead_remember_cookie`, against the account when the token was found. Tokens of browsers that never come back stay until the nightly [prune](operator-commands.md#pruning) deletes them past their expiry.

A return whose account newly owes [enrollment](enrollment.md) is refused rather than held, because nothing a session enrolls may rest on an earlier sign-in. Keystone deletes the token and the cookie, records `request.rejected` with the reason `keystone.enrollment_owed`, and the sign-in page says "Please sign in again to finish setting up two-factor authentication." The user signs in again, answers the challenge, enrolls and can tick the box once more.

## The trade-off

NIST SP 800-63B asks an account with a second factor to authenticate again at least every 12 hours. Remember me departs from that on purpose: a remembered browser skips both factors for up to the lifetime. An app that asks for a password and a code every morning teaches its users to pick weak ones. What bounds the cookie instead is that the user asked for it, that it exists only after a sign-in that proved every factor the account holds, and that its lifetime is fixed. An app that must meet the NIST limits sets `remember.lifetime_seconds` to `0`.

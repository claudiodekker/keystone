# TOTP

`keystone-totp` lets users answer the [second-factor challenge](challenge.md) with a six-digit code from an authenticator app, such as 1Password, Google Authenticator or Authy. It serves the challenge and [enrollment](enrollment.md), always as a form, and never counts as a first factor.

## Enrolling

Enrolling makes a new 160-bit key and shows it three ways: as a QR code to scan, in Base32 to type by hand, and as the `otpauth://` link the QR code holds, which adds the key to an authenticator app on the same device. The key is labelled with your `app.name` and the account's first alert address. The user types back a code the new key makes, and the key is stored with that code's step as the last one accepted, so the same code can't answer the next challenge. A code the key doesn't make records `proof.rejected` with the reason `totp.mismatch` and keeps the key, so the user can try again with the same QR code.

Keystone renders the QR code on the server as an SVG, with `bacon/bacon-qr-code`, and hands it to the page as a `data:` URI in the ceremony's `qr` field, beside `key` and `uri`. It draws the code from the link on every request and keeps only the key and the link in the session, so enrolling works on every session driver, the `cookie` driver included. The key never goes to another service to be drawn. The published `resources/js/partials/Totp.vue` shows it in an `<img>`. If your app sends a `Content-Security-Policy` with an `img-src` directive, that directive must allow `data:` on the enrollment pages. The package requires PHP's `xmlwriter` extension to draw the SVG.

A user enrolls TOTP in two places. A sign-in that [owes a second factor](enrollment.md) is held until it enrolls one, and a signed-in user adds one from the [security settings](security-settings.md#adding-a-credential), behind sudo. Both show the same form.

An account holds one TOTP key. Enrolling a new one from the security settings replaces every TOTP credential the account holds, a disabled one included, and signs out the account's other sessions, because whoever held the old key no longer holds a factor of the account. An account's first key signs nobody out. Both record `credential.added` with flow `settings`. A held sign-in never replaces anything: it only enrolls when the account holds no second factor it can use.

## Codes

Codes follow RFC 6238 with the settings every authenticator app uses: HMAC-SHA1, six digits and a new code every 30 seconds. See [ADR 0017](adr/0017-totp-keeps-hmac-sha1.md) for why SHA-1. Spaces in a typed code are ignored, so `123 456` works.

A code is accepted from the current 30 seconds and, by default, from the 30 seconds either side, so a code typed as it changes, or on a phone whose clock is a little off, still works. Each code works once: once a code is accepted, that code and every earlier one are refused, even when two requests send it at the same moment. See [ADR 0016](adr/0016-totp-accepts-one-step-either-side-and-each-step-once.md).

## The window

Publish the package's config to change how far either side of now a code may come from:

```shell
php artisan vendor:publish --tag=keystone-totp-config
```

| Setting | Default | What loosening it costs |
|---|---|---|
| `window_steps` | `1`: the 30 seconds either side | Each step added on a side lets every guess hit two more codes: a window of `n` accepts 2n+1 codes, so a guess succeeds about (2n+1) times in a million. `0` accepts the current code only. |

`window_steps` must be a whole number of at least 0, or your app refuses to boot (see [Configuration](configuration.md#boot-checks)).

## Guessing

A code has a million values, so wrong TOTP codes count harder than other wrong answers. They share one count per account across the challenge and the [sudo replay](sudo.md#the-replay): 20 an hour (your `keystone.rate_limits.failed_attempts_per_hour`), and never more than 100 in 24 hours, a ceiling no setting changes. See [Rate limiting](rate-limiting.md).

With the default window three codes are valid at once, so a guesser who holds the first factor and spends all 100 guesses a day has about a 0.03% chance a day of hitting one. That is 100 guesses at 3 in a million each.

A wrong code records `proof.rejected` with the reason `totp.mismatch`, and a code already used `totp.replayed`.

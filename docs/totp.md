# TOTP

`keystone-totp` lets users answer the [second-factor challenge](challenge.md) with a six-digit code from an authenticator app, such as 1Password, Google Authenticator or Authy. It serves the challenge and [enrollment](enrollment.md), always as a form, and never counts as a first factor.

## Enrolling

Enrolling makes a new 160-bit key and shows it in Base32, with an `otpauth://` link that adds it to an authenticator app, labelled with your `app.name` and the account's first alert address. The user types back a code the new key makes, and the key is stored with that code's step as the last one accepted, so the same code can't answer the next challenge. A code the key doesn't make records `proof.rejected` with the reason `totp.mismatch`. The page shows the key and the link, not a QR code.

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

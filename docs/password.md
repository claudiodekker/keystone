# Password

`keystone-password` lets users sign in with a password. It serves sign-in, registration and enrollment, always as a form, and is a first factor only: a password never counts as a second factor.

## Hashing

Passwords are hashed with Laravel's `Hash`, using your app's `config/hashing.php`. Keystone adds no settings of its own and encrypts every hash with your app key before storing it.

A stored bcrypt, argon2i or argon2id hash is checked with its own algorithm, whatever your current driver, so you can switch drivers without `HASH_VERIFY=false`. After a successful sign-in, a hash made with another driver or an older cost is replaced with a fresh one under your current settings. That doesn't end the account's other sessions. Set `hashing.rehash_on_login` to `false` to keep old hashes as they are.

An imported hash is the plain string `Hash` writes (`$2y$…`, `$argon2i$…` or `$argon2id$…`), as Fortify stores it in `users.password`.

## New passwords

At registration and enrollment a new password must be typed twice, in `password` and `password_confirmation`.

## Length

Under bcrypt a new password may be at most 72 bytes (or your lower `hashing.bcrypt.limit`), because bcrypt ignores everything after that; non-ASCII characters take more than one byte each. Under argon2i or argon2id the limit is 1024 characters. See [ADR 0008](adr/0008-passwords-hash-with-the-apps-hasher-and-bcrypt-keeps-its-72-byte-cap.md) for why, and for how hashes imported from longer passwords behave.

A new password must also be at least `min_length` characters. By default that follows `keystone.require_second_factor`: 8 while every account must hold a second factor, and 15 while a password may be an account's only factor, as NIST SP 800-63B asks. Publish the package's config to set your own:

```shell
php artisan vendor:publish --tag=keystone-password-config
```

| Setting | Default | What loosening it costs |
|---|---|---|
| `min_length` | `null`: 8 with the second-factor mandate, 15 without | Shorter passwords are guessed sooner. It can't go below 8, or your app refuses to boot. |

Sign-in takes a password of any length.

## Unknown accounts

A sign-in naming no account, or an account without a password, is checked against a dummy hash so it takes as long as a wrong password. The dummy is cached in your default cache store, so use a persistent store (`redis`, `database`, `memcached` or `file`) in production.

## Spaces

Keystone never trims the `password`, `current_password` and `password_confirmation` fields, even if your `TrimStrings` middleware no longer excepts them. A password that starts or ends with a space is kept as typed.

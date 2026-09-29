# Password

`keystone-password` lets users sign in with a password. It serves sign-in, registration and enrollment, always as a form, and is a first factor only: a password never counts as a second factor.

## Hashing

Passwords are hashed with Laravel's `Hash`, using your app's `config/hashing.php`. Keystone adds no settings of its own and encrypts every hash with your app key before storing it.

A stored bcrypt, argon2i or argon2id hash is checked with its own algorithm, whatever your current driver, so you can switch drivers without `HASH_VERIFY=false`. After a successful sign-in, a hash made with another driver or an older cost is replaced with a fresh one under your current settings. That doesn't end the account's other sessions.

An imported hash is the plain string `Hash` writes (`$2y$…`, `$argon2i$…` or `$argon2id$…`), as Fortify stores it in `users.password`.

## Length

Under bcrypt a new password may be at most 72 bytes, because bcrypt ignores everything after that; non-ASCII characters take more than one byte each. Under argon2i or argon2id the limit is 1024 characters. See [ADR 0008](adr/0008-passwords-hash-with-the-apps-hasher-and-bcrypt-keeps-its-72-byte-cap.md) for why, and for how hashes imported from longer passwords behave.

Sign-in takes a password of any length.

## Spaces

Keystone never trims the `password`, `current_password` and `password_confirmation` fields, even if your `TrimStrings` middleware no longer excepts them. A password that starts or ends with a space is kept as typed.

# Password

`keystone-password` lets users sign in with a password. It serves sign-in, registration and enrollment, always as a form, and is a first factor only: a password never counts as a second factor.

Users add, change and remove their password from the [security settings](security-settings.md#passwords). Changing it asks for the current password, signs out every other session and records `credential.replaced`. While `keystone.methods` doesn't list passwords on `sign-in`, setting one is refused with "Passwords are not supported on this application."

## Hashing

Passwords are hashed with Laravel's `Hash`, using your app's `config/hashing.php`. Keystone adds no settings of its own and encrypts every hash with your app key before storing it.

A stored bcrypt, argon2i or argon2id hash is checked with its own algorithm, whatever your current driver, so you can switch drivers without `HASH_VERIFY=false`. After a successful sign-in, a hash made with another driver or an older cost is replaced with a fresh one under your current settings. That doesn't end the account's other sessions. Set `hashing.rehash_on_login` to `false` to keep old hashes as they are.

An imported hash is the plain string `Hash` writes (`$2y$…`, `$argon2i$…` or `$argon2id$…`), as Fortify stores it in `users.password`.

## New passwords

At registration and enrollment a new password must be typed twice, in `password` and `password_confirmation`, which the published forms mark `autocomplete="new-password"`, and fit what your hashing driver takes (see [Length](#length)). Keystone then checks that it is strong enough, in this order, and stops at the first check it fails:

1. it has at least the [minimum length](#minimum-length);
2. it contains no [context word](#context-words);
3. it isn't one of the [most common passwords](#common-passwords);
4. it hasn't appeared in a [known breach](#breached-passwords).

The last check is the only one that leaves your app, so a password the others refuse is never sent anywhere. Keystone never reads Laravel's `Password::defaults()`. To set your own rules in place of these checks, see [Your own rules](#your-own-rules). See [ADR 0024](adr/0024-keystone-checks-new-passwords-itself-and-one-app-hook-replaces-the-checks.md) for why.

## Minimum length

Publish the package's config to change the minimum length or add context words:

```shell
php artisan vendor:publish --tag=keystone-password-config
```

| Setting | Default | What loosening it costs |
|---|---|---|
| `min_length.second_factor_required` | `8` | Applies while `keystone.require_second_factor` is on. Shorter passwords are faster to guess offline from a stolen hash. |
| `min_length.second_factor_optional` | `15` | Applies when `keystone.require_second_factor` is off, so a password may be an account's only factor. NIST SP 800-63B asks for 15 characters there. |
| `context_words` | `[]` | Nothing: each word you add is one more a password may not contain. |

Each minimum counts characters, not bytes, and must be a whole number of at least 8, or your app refuses to boot (see [Configuration](configuration.md#boot-checks)). Your file is merged over the package's key by key, so setting one minimum keeps the other's default.

## Context words

A new password may not contain a word of your app's name (`app.name`), of the host of your app's URL (`app.url`), of a `context_words` entry, or of the part before the `@` of any email address the user holds, verified or not. Each of these is split into words on anything but letters and digits, and only words of at least 4 characters count. Case is ignored. For an app named "Acme Payroll" at `https://portal.acme.test`, a user `jane.doe@example.com` can't choose `Payroll2026!`, `my-portal-pass` or `JaneLovesCats`.

The domain of an email address never counts, so `jane@gmail.com` may still choose a password containing `gmail`. `context_words` must be a list of strings, or your app refuses to boot.

## Common passwords

A new password may not be one of the 10,000 most common passwords, ignoring case. The list is SecLists' `10k-most-common.txt`, bundled with the package under the MIT License, so the check needs no network.

## Breached passwords

A new password that passes every other check is looked up in [Have I Been Pwned's Pwned Passwords](https://haveibeenpwned.com/Passwords) range API, and refused with Laravel's `validation.password.uncompromised` message if any breach lists it. Only the first 5 characters of the password's SHA-1 hash are sent, with padding asked for, so neither the password nor its hash leaves your app.

Keystone gives the API 5 seconds, follows no redirect and doesn't retry. If it can't get an answer, it accepts the password on the other checks and logs a warning on your `keystone.log_channel`, naming what went wrong and nothing of the password. This lookup never touches the verifier behind Laravel's own `Password::uncompromised()`.

Keystone behaves the same in your tests. A test that calls `Http::preventStrayRequests()` and fakes nothing gets the outage: the password is accepted on the other checks and the warning is logged. To control the answer, bind `ClaudioDekker\Keystone\Password\FakeBreachedPasswords` with the passwords it should report as breached:

```php
use ClaudioDekker\Keystone\Password\BreachedPasswords;
use ClaudioDekker\Keystone\Password\FakeBreachedPasswords;

$breaches = $this->app->instance(BreachedPasswords::class, new FakeBreachedPasswords(['hunter2hunter2']));

// Submit 'hunter2hunter2' as a new password, then:
$response->assertSessionHasErrors('password');
expect($breaches->asked)->toBe(['hunter2hunter2']);
```

The fake records every password it was asked about in `asked`, so a test can also prove a password another check refused was never looked up.

## Your own rules

To set your own rules in place of these four checks, call `PasswordRules::defaults()` once in a service provider's `boot()` method. It takes a rule, a list of rules or a callback that returns either. When the callback returns `null`, Keystone's checks apply. This keeps the strict checks in production while a local or staging app takes any password:

```php
use ClaudioDekker\Keystone\Password\PasswordRules;
use Illuminate\Validation\Rules\Password;

public function boot(): void
{
    PasswordRules::defaults(fn () => $this->app->isProduction() ? null : Password::min(1));
}
```

Your rules replace all four checks, not only the ones you name, so `Password::min(1)` takes `password` without looking it up. Loosening them in production lets your users choose passwords an attacker guesses first, from a stolen hash or at your sign-in form. Whatever you return, a new password must still be typed twice and fit your hashing driver, and sign-in never reads these rules.

## Length

Under bcrypt a new password may be at most 72 bytes (or your lower `hashing.bcrypt.limit`), because bcrypt ignores everything after that; non-ASCII characters take more than one byte each. Under argon2i or argon2id the limit is 1024 characters. See [ADR 0008](adr/0008-passwords-hash-with-the-apps-hasher-and-bcrypt-keeps-its-72-byte-cap.md) for why, and for how hashes imported from longer passwords behave.

Sign-in takes a password of up to 1024 characters under every driver, so a hash imported from a longer bcrypt password still matches its first 72 bytes. A longer one is refused with a validation error on `password` before anything is hashed, so it costs no hashing time and doesn't count as a wrong answer.

## Unknown accounts

A sign-in naming no account, or an account without a password, is checked against a dummy hash so it takes as long as a wrong password. The dummy is cached in your default cache store, so use a persistent store (`redis`, `database`, `memcached` or `file`) in production.

## Spaces

Keystone's password package never trims the `password`, `current_password` and `password_confirmation` fields, even if your `TrimStrings` middleware no longer excepts them. A password that starts or ends with a space is kept as typed.

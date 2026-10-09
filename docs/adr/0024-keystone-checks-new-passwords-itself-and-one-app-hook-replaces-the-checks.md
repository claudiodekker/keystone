# Keystone checks new passwords itself, and one app hook replaces the checks

`keystone-password` decides whether a new password is strong enough with its own chain, following NIST SP 800-63B and ASVS 5.0 V6.2: a minimum length, then a blocklist of context words, the 10,000 most common passwords and a Pwned Passwords range query, in that order. The minimum is 8 characters while `keystone.require_second_factor` is on and 15 when a password may be an account's only factor. Both sit in the shipped config as `min_length.second_factor_required` and `min_length.second_factor_optional`, so neither default hides in code (ADR 0012), and boot refuses either below 8. No composition rules apply.

The chain doesn't read Laravel's `Password::defaults()`. Apps and starter kits set it for their own forms, and such an app would silently replace Keystone's chain without meaning to. The chain also isn't an `env()` switch or a config key such as `checks_enabled`, since ADR 0012 keeps security settings out of `env()` and a key that turns a control off reads as harmless in a published file.

An app replaces the chain through one hook, `PasswordRules::defaults()`, set once in a service provider like Laravel's own. It takes rules or a callback, and a callback that returns `null` keeps Keystone's chain, so the usual setup keeps the strict checks in production and loosens them elsewhere:

```php
PasswordRules::defaults(fn () => $this->app->isProduction() ? null : Password::min(1));
```

Rules the app returns replace the minimum length and the blocklist whole. Confirmation and the hashing driver's length cap (ADR 0008) always apply, because they decide whether the stored hash matches what was typed, not how strong it is.

## Consequences

- The rules bail at the first failure and the breach lookup runs last, so a password any local check refuses never leaves the app. Only the first 5 characters of its SHA-1 are sent, with padding.
- The breach lookup fails open: an outage, a timeout, a redirect or any status but 200 accepts the password on the local checks and logs a warning on `keystone.log_channel`. It gives the API 5 seconds and doesn't retry, since the user is waiting.
- The lookup sits behind a `BreachedPasswords` interface at the package root. The provider binds the real client in every environment, so tests run the same code as production. A test suite that prevents stray requests gets the fail-open warning, and a test that needs an answer binds the shipped `FakeBreachedPasswords`. An app can bind its own source, such as a mirror. Laravel's `UncompromisedVerifier` is left alone, so the app's `Password::uncompromised()` behaves as before.
- Context words come from the signed-in account through the guard, as Laravel's `current_password` rule reads it, so the `CredentialType` contract is unchanged. Usernames, the pending account at recovery and the typed address at registration join the context with the tickets that build them.
- The common list is vendored from SecLists at a pinned commit and normalised once, and a unit test pins its normalisation. Updating it means fetching a newer commit and recording it in the notice next to the file.
- A production app that returns its own rules gives up the blocklist entirely. That is the app's choice to make, and the docs state its cost.

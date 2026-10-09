# A replacement records credential.replaced, read from the write

ADR 0023 lets a proof say that its credential replaces every credential of its type the account holds, and records `credential.added` for it. A password change and a security key upgraded to a passkey also take the place of what the account held, and the spec records `credential.replaced` for them. This decides how core tells an addition from a replacement without naming a type, and what the user is told after each.

- **The account change reads it from what it deleted.** `AccountChange::enroll()` already ends the account's other sessions when deleting the credentials of the type removed a row. The same fact now picks the event: `credential.replaced` when a row went, `credential.added` when none did, as for an account's first credential of a replacing type. It also returns `SettingsEnrollmentResult::REPLACED` or `ADDED`, so the controller's status, the event and the epoch move come from one fact and can't disagree. The proof needs no new flag.
- **TOTP re-enrollment records `credential.replaced`.** This supersedes ADR 0023's "A replacement records `credential.added` only". The event still names the new credential, and no `credential.removed` is recorded for the one it replaced. `credential.replaced` alerts, with its own mail, because the old credential stopped working and the account's other sessions were signed out.
- **A replacement flashes `credential-replaced`.** It says the other sessions were signed out. The `enrolled` status offers to sign out the other sessions, which a replacement already did, so a replacement no longer shows that offer.

## Consequences

- An app that listened for `credential.added` to learn of a new TOTP key also listens for `credential.replaced`.
- The alert for a replaced credential says the account's other sessions were signed out. The alert for an added one doesn't.

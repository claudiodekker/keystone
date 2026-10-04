# One account-change unit is the only write door

Every write to an account's credentials, addresses or credential epoch goes through one core unit, `AccountChanges::change($account, fn (AccountChange $change) => ...)`. It runs the same steps every time: lock the account's row, read the alert recipients, apply the change, move the epoch if the change removed, replaced or ended anything, commit, then record the change's events. Only core's own writes use it; it is `@internal` and never bound in the container, so no app can put another in its place.

Deriving the epoch move from what the change did, rather than having each flow remember to move it, means a later flow can't forget to end the other sessions after removing a credential. Recording after commit means a change that rolls back, including inside a transaction the caller opened, leaves no audit entry and alerts nobody. Reading recipients before applying means an address the change removes still hears about it.

## Consequences

- Which changes move the epoch is one table-driven test through the unit (`AccountChangesTest`). Each later ticket that adds a change adds its row: removing a credential or unlinking OAuth, a key-to-passkey upgrade, regenerating recovery codes over a live set, a password change or removal, sign out others, suspension, deletion and completed recovery move it; adding a credential, a first password or first codes, a rehash, revoking one session, a sudo grant, a restore and unsuspending don't.
- The mover's own session survives: when the change moves the epoch and the current session was signed in as the account on the epoch that moved, it is re-stamped with the new one and its id is rotated. A session already on an older epoch is not revived.
- Operators end sessions with `keystone:end-sessions {user}` or `--all`, or by dispatching the `EndSessions` / `EndEverySession` jobs from an admin panel. Keystone doesn't authorize either; the app decides who may run them.
- `sessions.terminated` is recorded with actor `operator` and the operator the command or job names, in a fixed `operator` field (cut to 64 characters, control characters replaced), since events carry no free-form metadata.
- `--all` moves every account's epoch in one query, the operator's own session included, and records one `sessions.terminated` about nobody, with reason `keystone.every_account`. Like every event about nobody it is a log line only, not a row on each account's trail, so ending every session costs one update however many accounts there are.
- Ending an account's sessions doesn't yet forget its known devices: they don't exist yet, and arrive with their own ticket (#41), which hooks into the unit. It alerts the owner at the recipients the unit read before the change, unless the operator suppresses it (ADR 0015).
- What an account owes is read from its credentials and recovery codes when it is needed (ADR 0018), so the unit stores nothing for it.
- An arch test keeps the writers of `Credentials` and `RecoveryCodes` (`store`, `replaceSecret`, `replace`, `spend`) inside `AccountChange`, so a later flow can't write around the unit.

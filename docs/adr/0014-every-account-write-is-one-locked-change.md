# Every account write is one locked change

Every write to an account's credentials, addresses or credential epoch is one account change that runs the same steps every time: lock the account's row, read the alert recipients, apply the change, move the epoch if the change removed, replaced or ended anything, commit, then record the change's events. Only Keystone's own writes take this path, and no app can put another in its place.

Deriving the epoch move from what the change did, rather than having each flow remember to move it, means a later flow can't forget to end the other sessions after removing a credential. Recording after commit means a change that rolls back, including inside a transaction the caller opened, leaves no audit entry and alerts nobody. Reading recipients before applying means an address the change removes still hears about it.

Creating an account is the one write that starts without a row to lock. The app's `CreateAccount` action inserts the users row, and everything else (the verified address, the first credential and `account.registered`) is written as an account change on that row, in the same transaction. A unique violation on the address rolls the users row back with the rest, so no half-created account is left behind. The event names the new address as its recipient, because there are no recipients before the change.

A change may name the session writing it: a pending sign-in, a sudo grant or a sudo in progress. Once the account is locked, and before anything is applied, the change is refused when the account is barred, when a pending sign-in is no longer on the account's epoch, or when a sudo grant has ended. A flow can then never write for a session that lost the right to while it waited for the lock, and a refusal writes and records nothing.

## Consequences

- Removing a credential or unlinking OAuth, a key-to-passkey upgrade, regenerating recovery codes over a live set, a password change or removal, sign out others, suspension, deletion and completed recovery move the epoch. Adding a credential, a first password or first codes, a rehash, revoking one session, a sudo grant, a restore and unsuspending don't.
- The mover's own session survives: when the change moves the epoch and the current session was signed in as the account on the epoch that moved, it is re-stamped with the new one and its id is rotated. A session already on an older epoch is not revived.
- Operators end sessions with `keystone:end-sessions {user}` or `--all`, or by dispatching a job from an admin panel. Keystone doesn't authorize either; the app decides who may run them.
- `sessions.terminated` is recorded with actor `operator` and the operator the command or job names, in a fixed `operator` field (cut to 64 characters, control characters replaced), since events carry no free-form metadata.
- `--all` moves every account's epoch in one query, the operator's own session included, and records one `sessions.terminated` about nobody, with reason `keystone.every_account`. Like every event about nobody it is a log line only, not a row on each account's trail, so ending every session costs one update however many accounts there are.
- Ending an account's sessions alerts the owner at the recipients read before the change, unless the operator suppresses it (ADR 0015).
- What an account owes is read from its credentials and recovery codes when it is needed (ADR 0018), so the change stores nothing for it.
- No flow writes credentials or recovery codes around an account change.

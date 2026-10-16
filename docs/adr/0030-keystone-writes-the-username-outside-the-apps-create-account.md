# Keystone writes the username outside the app's CreateAccount

While `username_based` is on, Keystone validates the username at the finish, normalizes it and writes it onto the users row itself, through the account change, after the app's `CreateAccount` has inserted the row. `CreateAccount` never sees the username: its rules don't name it and it receives no such key. The write sits inside the transaction that writes the address and the credential, and stores the normalized form once. A unique violation on the column rolls the whole account back, like a taken address, and the finish page answers with the error a taken username gets at validation.

A username is unique across every row until the row is hard-deleted, soft-deleted and invalidated accounts included. That is a security decision: a username that frees up when its holder is deleted lets someone take the name of an account that existed. The unconditional unique index is the single source of truth. It ignores both the deleted and the invalidated columns, and validation looks the name up without the soft-delete scope so it can refuse politely before any write. The column compares exactly (`utf8mb4_bin`, Postgres `C`), so the stored lowercase form is the only form that matches.

## Considered

Passing the username through `CreateAccount`, by adding it to its rules while the setting is on and having it write the username. The interface looks smaller and the app could use the username while it builds the row, for a slug say. Keystone rejected it for three reasons.

- `CreateAccount` is swappable, and the username's uniqueness and normalization are security decisions. Keystone's standards keep a class that makes such a decision from being swappable. An override that skips the parent's rules would drop the taken check, and one that writes the raw value would store `JaneDoe`, which never matches under binary comparison and so never signs in.
- Every override would have to keep the rule, the normalization and the write in step. A forgotten write silently creates an account with no username.
- A unique violation inside the app's action surfaces as a server error unless every override catches it and turns it into a validation error.

Registration already says Keystone writes the address and the credential itself. The username joins that list.

## Consequences

- An app reads `$user->username` after registration. It can't read it inside `CreateAccount`.
- An app must not name a field `username` in its own `CreateAccount` rules, as it must not name `password`.
- The row is written twice, once by the app's insert and once by Keystone. The second write is quiet and fires no model events.
- The column is nullable. Accounts that predate the setting, and rows made by test factories, hold none and sign in by address.
- The username availability oracle is documented beside the address one.
- A username change from the security settings goes through the account change too, so a lost race rolls back and answers the same way.

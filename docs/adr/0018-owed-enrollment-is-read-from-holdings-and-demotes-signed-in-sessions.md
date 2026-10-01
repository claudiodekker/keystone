# Owed enrollment is read from holdings and demotes signed-in sessions

Both mandates default on: every account must hold a second factor and recovery codes. Whether an account owes enrollment has to be known on every request that asks who is signed in, because a mandate turned on, or a second factor removed, must not leave a signed-in session that skips it. Counting credentials and codes on every request would add two queries to every page.

- **Holdings are columns.** `users.has_second_factor` and `users.has_recovery_codes` are written by the account change unit after each change applies, inside its lock, from the credentials and codes as they stand. Every write already goes through that unit (ADR 0014), so the columns can't drift. "Owes" is the mandate config read against them, so turning a mandate on or off needs no backfill.
- **The decision stays one module.** `SignInDecision` decides the challenge, enrollment and sign-in, at the first factor and again for a pending sign-in as its account stands now. A second factor added elsewhere while a sign-in is held at enrollment sends it back through the challenge, because a pending sign-in remembers whether it passed one.
- **A signed-in session that newly owes is demoted, not ended.** The guard holds it at enrollment with a rotated id and no ceremony, records `sign_in.held` with reason `demoted`, and keeps the page the user asked for. It sends no `Clear-Site-Data`: the user is the same person, about to come straight back. A JSON client gets a 403, not a 401, so it can tell owing enrollment from being signed out. The response is a swappable action, `RespondToDemotedSession`.
- **Enrollment writes once.** The credential, the holdings and `credential.added` commit in one account change that moves no epoch, after the ceremony's answer is verified. A rejected answer writes nothing, and the ceremony is forgotten as the change commits, so a retried answer finds no ceremony instead of a second row.
- **Recovery codes are staged in the session.** The set lives in a ceremony slot until the user types one back, so a reload shows the same codes and nothing is stored that the user never saw. A first set alerts nobody; replacing a set ends the account's other sessions and alerts.

## Consequences

- A session that signed in with a first factor proving two factors on its own, such as a passkey, is demoted as if it had proven one when its account holds no second factor, because a signed-in session doesn't remember its first factor. No such type ships yet; the passkey package must count as a held second factor.
- Holdings are only as fresh as the last account change. A credential written around the unit, such as by a migration script, leaves them stale until the account's next change.
- Production refuses to boot with `require_second_factor` on and no listed type that can be enrolled as a second factor, so no account can be held at a step it can't finish.

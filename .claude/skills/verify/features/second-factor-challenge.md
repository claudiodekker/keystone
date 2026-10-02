# Second-factor challenge

After an account with a second factor passes its first factor, the sign-in is held and the user lands on `Confirm it's you` at `/auth/login/challenge`. They answer with a code from their authenticator app, or switch to another type the account holds, or cancel. Only a correct answer signs the session in.

## Sub-features

- `challenge-totp` signs in with the current six-digit TOTP code.
- `challenge-refused` refuses a wrong code with "The provided credential is invalid."
- `challenge-replayed` refuses a code that was already accepted.
- `challenge-switch` swaps the form to another type from the `Use something else:` list.
- `challenge-cancel` drops the held sign-in and shows "Sign-in cancelled. You were not logged in."
- `challenge-guarded` sends a session with no held sign-in from `/auth/login/challenge` back to sign in.

## How to get to it (user POV)

- Sign in with the password of an account that holds a TOTP authenticator or recovery codes.
- Reopen `/auth/login/challenge` while a sign-in is held, for up to 15 minutes after the first factor.

## Driving it with Playwright

Preconditions:

- A fresh run with `app.sh second-factor <run>` done. `fixture(run)` returns `totp_key`.
- Signed in with the password as in [Sign in](./sign-in.md), with the heading `Confirm it's you` visible.

- **Answer with TOTP.** `page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key))`, `page.getByRole('button', { name: 'Verify' }).click()`. `You're signed in.` appears on `/`. `signed_in` is recorded with flow `challenge` and credential type `totp`. The full flow is `scripts/scenarios/sign-in-with-totp.mjs`.
- **Wrong code.** Fill `000000` and choose `Verify`. The page stays on the challenge and shows `The provided credential is invalid.` under the field. `proof.rejected` is recorded with reason `totp.mismatch`.
- **Replayed code.** Sign in, answer with `totp(key)`, sign out, sign in again and answer with the same code inside the same 30 seconds. It is refused and `proof.rejected` is recorded with reason `totp.replayed`.
- **Switch type.** `page.getByRole('button', { name: 'recovery-code' }).click()`. The field becomes `Recovery code` and a `totp` button takes its place in the list.
- **Cancel.** `page.getByRole('button', { name: 'Cancel sign-in' }).click()`. The heading `Sign in` appears at `/auth/login` with `Sign-in cancelled. You were not logged in.`. A following `page.goto('/auth/login/challenge')` lands on `/auth/login`.

## Gotchas

- A replay check is time-bound. The window accepts the codes of the 30 seconds either side of now, and each code once, so build the replay case in one tight sequence.
- After a refusal the code stays in the input. That is the browser's form state, not a value the server sent back.
- The type buttons are named by type id (`recovery-code`, `totp`), not by a friendly label.
- Cancel posts `DELETE` through `_method` and answers 303. Expect that in `network.log`.

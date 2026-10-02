# Recovery codes

A user who lost their authenticator answers the challenge with one of their recovery codes. A correct code signs them in and is spent. Their owner gets a mail saying how many codes are left. The account's last code is refused and kept for account recovery.

## Sub-features

- `recovery-sign-in` signs in with an unspent code, typed in any case, with or without dashes.
- `recovery-spent` deletes the code that answered, so it never works again.
- `recovery-alert` mails "A recovery code was used on your account" with the count left.
- `recovery-last-kept` refuses the last code with "This is your last recovery code. It is kept for account recovery and cannot be used here."

## How to get to it (user POV)

- On the challenge, choose `recovery-code` under `Use something else:`.
- On the challenge of an account with recovery codes and no other usable second factor, where the recovery-code form is preselected.

## Driving it with Playwright

Preconditions:

- A fresh run with `app.sh second-factor <run>` done. `fixture(run).recovery_codes` holds the 8 codes.
- Held on the challenge as in [Second-factor challenge](./second-factor-challenge.md).

- **Switch.** `page.getByRole('button', { name: 'recovery-code' }).click()`. The field `Recovery code` appears.
- **Answer.** `page.getByLabel('Recovery code').fill(codes[0].toLowerCase().replaceAll('-', ''))`, `page.getByRole('button', { name: 'Verify' }).click()`. `You're signed in.` appears on `/`. `recovery_code.used` and then `signed_in` are recorded, both with credential type `recovery-code`.
- **Spent.** `app.sh sql <run> "select count(*) from user_recovery_codes"` reads `7`. Answering a later challenge with the same code is refused with `The provided credential is invalid.`.
- **Alert.** `app.sh mail <run>` prints `To: jane@example.com`, `Subject: A recovery code was used on your account` and `You have 7 recovery codes left.`.
- **Last code kept.** Leave one code with `app.sh sql <run> "delete from user_recovery_codes where id <> (select max(id) from user_recovery_codes)"`, then answer with the code you inserted last, `codes[7]`. The last-code message appears and the count stays `1`.

## Gotchas

- The table stores only SHA-256 digests. Map a row back to a code only by insertion order from `second-factor`.
- Alerts stay in the run's `jobs` table until `app.sh mail` drains them. An empty mail log before the drain is expected.
- Recovery codes have their own failed-attempt limit. Wrong-code experiments can throttle later scenarios in the same run.

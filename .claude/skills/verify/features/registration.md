# Registration

A guest asks for a registration link at `/auth/register`, reached from the sign-in page's `Create an account` link. Every valid address lands on "Check your email". A free address is mailed a link. An address an account already holds is mailed nothing, and that account's owner is alerted instead. Opening the link shows a page saying the address is verified, with one `Continue` button. Opening it spends nothing. The button spends the link and lands on the finish page, which shows the proven address. A spent link is refused in any browser. On the finish page a name and a password twice create the account. The workbench requires a second factor and recovery codes, so the new account lands on the enrollment it owes, and is signed in once it has set up an authenticator app and saved its codes. `Cancel registration` on the finish page ends the registration and creates nothing.

## Sub-features

- `registration-entry` the sign-in page links to `/auth/register` with `Create an account` while a listed type serves registration, as the workbench's password does.
- `registration-free` a free address lands on `/auth/register/link-sent` with the heading `Check your email` and is mailed `Confirm your email address`.
- `registration-taken` `jane@example.com` lands on the same step, is mailed no link, Jane gets `Someone tried to sign up with your email address`, and `address.claim_attempted` is recorded on account `1` with flow `registration`.
- `registration-link-built` the mailed link starts with the run's URL, never shows the address, and works for 10 minutes.
- `registration-link-open` opening the link shows the heading `Email address verified`, sends `Referrer-Policy: no-referrer`, and leaves `used_email_links` empty.
- `registration-link-spend` `Continue` lands on `/auth/register/finish` with the heading `Finish creating your account` and the address, and adds one row to `used_email_links`.
- `registration-link-replay` the same link, opened in another browser, lands on `/auth/register/link-expired` with the heading `That link is no longer valid` as soon as it is opened.
- `registration-finish-guest` a browser that spent no link and opens `/auth/register/finish` lands on `/auth/register` with no status.
- `registration-finish` `Name`, `Password` and `Confirm password` filled and `Create account` chosen land on `/auth/login/enrollment` with the heading `Set up two-factor authentication` and a `Sign out` button in place of `Cancel sign-in`; the address is the new account's verified primary one, and `account.registered` and `sign_in.held` are recorded with flow `registration`.
- `registration-enrollment-completed` enrolling `totp` and saving the recovery codes land on home with `You're signed in.`; `enrollment.completed` and `signed_in` are recorded with flow `enrollment`, `known_device` `0`, and no `New sign-in to your account` alert is mailed.
- `registration-welcome` the new address is mailed `Welcome to <app name>`.
- `registration-cancel` `Cancel registration` on the finish page lands on `/auth/register` reading `Registration cancelled. No account was created.`, and the address is on no account.

## How to get to it (user POV)

- Choose `Sign in` on home (`/`), then `Create an account` on the sign-in page.
- Open `/auth/register` directly.
- Open the link from the mail `Confirm your email address`, then choose `Continue`.
- Fill the finish page and choose `Create account`.

## Driving it with Playwright

Preconditions:

- A fresh run that passes `app.sh doctor`. Jane needs no second factor.

- **Ask for a link.** `page.getByRole('link', { name: 'Create an account' }).click()` on `/auth/login`, then `page.getByLabel('Email address').fill(address)` and `page.getByRole('button', { name: 'Send me a link' }).click()`. Wait for the heading `Check your email`.
- **Read the mail.** `app.sh mail <run>` drains the queue and prints each mail. A link mail has `To: <address>` and `Subject: Confirm your email address`, and its `href` holds the link with `&amp;` between its query values. Normalise `\r\n` to `\n` before matching, and replace `&amp;` with `&` before opening it.
- **Open the link.** `const response = await page.goto(link)`, then wait for the heading `Email address verified`. `response.headers()['referrer-policy']` is `no-referrer`, and `app.sh sql <run> "select count(*) from used_email_links"` is still `0`.
- **Spend it.** `page.getByRole('button', { name: 'Continue' }).click()`, then wait for the heading `Finish creating your account` and `page.getByText(address)`. `used_email_links` now holds one row.
- **Replay it.** In a second browser from `open(run, name)`, open the same link. The heading `That link is no longer valid` appears at `/auth/register/link-expired`.
- **Finish.** On the finish page, `page.getByLabel('Name').fill(name)`, `page.getByLabel('Password', { exact: true }).fill(password)` and `page.getByLabel('Confirm password').fill(password)`, then `page.getByRole('button', { name: 'Create account' }).click()`. Wait for the heading `Set up two-factor authentication`.
- **Enroll what it owes.** `page.getByRole('link', { name: 'totp' }).click()`, then fill `Code from your authenticator app` with `totp(await page.locator('code').innerText())` and choose `Set up`. On `Save your recovery codes`, fill `Type one of the codes to confirm you saved them` with the first `li` and choose `I saved them`. Wait for `You're signed in.`.
- **Cancel.** On the finish page, `page.getByRole('button', { name: 'Cancel registration' }).click()`, then wait for `Registration cancelled. No account was created.`.
- **Proof.** `app.sh sql <run> "select type, flow, user_id from user_security_events where type = 'address.claim_attempted'"` shows Jane's row. A refused link records `request.rejected` about nobody, so it is only in the log, not in the table. `app.sh sql <run> "select type, flow, known_device from user_security_events where user_id = <id> order by id"` shows the new account's trail: `account.registered`, `sign_in.held`, `credential.added`, `recovery_codes.generated`, `sudo.granted`, `enrollment.completed` and `signed_in`. `app.sh mail <run>` prints the welcome mail.

The whole path is `scripts/scenarios/registration.mjs`: `node .claude/skills/verify/scripts/scenarios/registration.mjs <run>`. Run it on a fresh run: it expects `used_email_links` to start empty, and Jane's address gets only 3 registration mails in 10 minutes.

## Gotchas

- The register page and the email submission take the same 300 ms whatever the address, so a taken address shows no sign of being taken in the browser. Read the mail log and the security events instead.
- Each address gets at most 3 registration mails in 10 minutes, alerts included. A run that asks for Jane's address more often mails nothing more. Start a fresh run, or use another address.
- The workbench lists the password on every surface, so registration is always open there, and the "Registration is not available." refusal can't be reached. The feature tests cover it.
- The password may not contain a word of 4 or more characters from the address's local part. The scenario's addresses carry a timestamp, which counts as such a word, so its password holds no long run of digits.
- The lost race (another account holding the address between the link and the finish) needs a second account to verify the address meanwhile, which no browser step does. The feature tests cover it.
- Mails wait on the run's `database` queue until `app.sh mail` drains it, so read the link only after asking for it.

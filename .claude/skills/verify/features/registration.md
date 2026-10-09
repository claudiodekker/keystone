# Registration

A guest asks for a registration link at `/auth/register`, reached from the sign-in page's `Create an account` link. Every valid address lands on "Check your email". A free address is mailed a link. An address an account already holds is mailed nothing, and that account's owner is alerted instead. Opening the link shows a page with one `Continue` button that changes nothing. The button spends the link and lands on the finish page, which shows the proven address. A spent link is refused in any browser.

## Sub-features

- `registration-entry` the sign-in page links to `/auth/register` with `Create an account` while a listed type serves registration, as the workbench's password does.
- `registration-free` a free address lands on `/auth/register/link-sent` with the heading `Check your email` and is mailed `Confirm your email address`.
- `registration-taken` `jane@example.com` lands on the same step, is mailed no link, Jane gets `Someone tried to sign up with your email address`, and `address.claim_attempted` is recorded on account `1` with flow `registration`.
- `registration-link-built` the mailed link starts with the run's URL, never shows the address, and works for 10 minutes.
- `registration-link-open` opening the link shows the heading `Continue from your email`, sends `Referrer-Policy: no-referrer`, and leaves `used_email_links` empty.
- `registration-link-spend` `Continue` lands on `/auth/register/finish` with the heading `Finish creating your account` and the address, and adds one row to `used_email_links`.
- `registration-link-replay` the same link, opened and continued in another browser, lands on `/auth/register/link-expired` with the heading `That link is no longer valid`.
- `registration-finish-guest` a browser that spent no link and opens `/auth/register/finish` lands on `/auth/register`.

## How to get to it (user POV)

- Choose `Sign in` on home (`/`), then `Create an account` on the sign-in page.
- Open `/auth/register` directly.
- Open the link from the mail `Confirm your email address`, then choose `Continue`.

## Driving it with Playwright

Preconditions:

- A fresh run that passes `app.sh doctor`. Jane needs no second factor.

- **Ask for a link.** `page.getByRole('link', { name: 'Create an account' }).click()` on `/auth/login`, then `page.getByLabel('Email address').fill(address)` and `page.getByRole('button', { name: 'Send me a link' }).click()`. Wait for the heading `Check your email`.
- **Read the mail.** `app.sh mail <run>` drains the queue and prints each mail. A link mail has `To: <address>` and `Subject: Confirm your email address`, and its `href` holds the link with `&amp;` between its query values. Normalise `\r\n` to `\n` before matching, and replace `&amp;` with `&` before opening it.
- **Open the link.** `const response = await page.goto(link)`, then wait for the heading `Continue from your email`. `response.headers()['referrer-policy']` is `no-referrer`, and `app.sh sql <run> "select count(*) from used_email_links"` is still `0`.
- **Spend it.** `page.getByRole('button', { name: 'Continue' }).click()`, then wait for the heading `Finish creating your account` and `page.getByText(address)`. `used_email_links` now holds one row.
- **Replay it.** In a second browser from `open(run, name)`, open the same link and choose `Continue`. The heading `That link is no longer valid` appears at `/auth/register/link-expired`.
- **Proof.** `app.sh sql <run> "select type, flow, user_id from user_security_events where type = 'address.claim_attempted'"` shows Jane's row. A refused link records `request.rejected` about nobody, so it is only in the log, not in the table.

The whole path is `scripts/scenarios/registration.mjs`: `node .claude/skills/verify/scripts/scenarios/registration.mjs <run>`. It uses a fresh address each time, so it runs again on the same run.

## Gotchas

- The register page and the email submission take the same 300 ms whatever the address, so a taken address shows no sign of being taken in the browser. Read the mail log and the security events instead.
- Each address gets at most 3 registration mails in 10 minutes, alerts included. A run that asks for Jane's address more often mails nothing more. Start a fresh run, or use another address.
- The workbench lists the password on every surface, so registration is always open there, and the "Registration is not available." refusal can't be reached. The feature tests cover it.
- The finish page shows the address only. Its form arrives with account creation.
- Mails wait on the run's `database` queue until `app.sh mail` drains it, so read the link only after asking for it.

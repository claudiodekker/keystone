# Sudo

A signed-in user opens the workbench's page behind sudo. Inside 15 minutes of signing in they get through. Once they end sudo, or it runs out, the page sends them to the sudo page at `/auth/sudo`, where they answer what a sign-in would ask (the password, then the TOTP code when Jane holds one) and land back on the page behind sudo.

## Sub-features

- `sudo-pass` a fresh sign-in reaches `/gated` without being asked anything.
- `sudo-end` the `End sudo` button on home drops the grant and lands on `/settings/security` with "Sudo has ended." shown.
- `sudo-gate` a session without sudo asking for `/gated` is redirected to `/auth/sudo`.
- `sudo-first-step` the sudo page offers the password with a `Confirm` button and no email field.
- `sudo-second-step` after the password, the same page offers the TOTP code when Jane holds an authenticator.
- `sudo-granted` the last answer lands on `/gated`.
- `sudo-refused` a wrong answer shows "The provided credential is invalid." and records `sudo.failed`.

## How to get to it (user POV)

- Choose `Page behind sudo` on home (`/`) while signed in.
- Choose `End sudo` on home or on the security page, then `Page behind sudo` on home again.

## Driving it with Playwright

Preconditions:

- Signed in as Jane by any route in [Sign in](./sign-in.md) or [Second-factor challenge](./second-factor-challenge.md), with `You're signed in.` visible on `/`.
- For the second step, `app.sh second-factor <run>` has given Jane a TOTP authenticator, and she signed in through the challenge.

- **Pass.** `page.getByRole('link', { name: 'Page behind sudo' }).click()`. The heading `A page behind sudo` appears at `/gated`. `page.getByRole('link', { name: 'Home' }).click()` goes back.
- **End sudo.** On home, `page.getByRole('button', { name: 'End sudo' }).click()`. The security page at `/settings/security` shows `Sudo has ended.`. `network.log` shows `POST /auth/sudo?_method=DELETE -> 303`. `page.goto('/')` returns home.
- **Gate.** `page.getByRole('link', { name: 'Page behind sudo' }).click()`. The heading `Confirm it's you` appears at `/auth/sudo`. `network.log` shows `GET /gated -> 302`.
- **First step.** `page.getByLabel('Password').fill(account.password)`, then `page.getByRole('button', { name: 'Confirm' }).click()`. Without an authenticator the heading `A page behind sudo` appears; with one, the label `Code from your authenticator app` appears on the same URL.
- **Second step.** `page.getByLabel('Code from your authenticator app').fill(totp(account.totp_key))`, then `Confirm`. The heading `A page behind sudo` appears at `/gated`.
- **Proof.** Rows in `user_security_events` in order: `sudo.granted` with reason `keystone.sign_in`, `sudo.revoked`, then `sudo.granted` with flow `sudo` and the credential type of the last answer. A wrong answer adds `sudo.failed` with flow `sudo`.

The whole path is `scripts/scenarios/sudo-replay.mjs`: `node .claude/skills/verify/scripts/scenarios/sudo-replay.mjs <run>`.

## Gotchas

- The code that answered the sign-in's challenge is refused at sudo inside the same 30-second step, as `sudo.failed` with `totp.replayed`. Answer with the next step's code, `totp(account.totp_key, Date.now() + 30_000)`, which the window accepts.
- The sudo page has no email field and no remember-me box. `getByLabel('Email address')` finds nothing there.
- Both steps render on `/auth/sudo`. Wait for the next step's label or the `A page behind sudo` heading, never for a URL change after the first `Confirm`.
- The page behind sudo is the workbench's own route. The security page needs no sudo, so it never shows the gate.
- `End sudo` sits on home, not on the gated page: the stub's `back()` would return to the gated page, meet the gate and land on the sudo page.
- The gated page is the workbench's own `settings/security` route behind `['auth', 'sudo']`. The stubs ship no gated page.
- The form posts with `_method=DELETE`, so the log shows a `POST` to `/auth/sudo` answered `303`.

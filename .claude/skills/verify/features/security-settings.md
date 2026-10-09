# Security settings

A signed-in user opens the security page from home. It lists the password and the authenticator app with when each was added and last used, how many recovery codes are left, and until when the session has sudo. Ending sudo there brings the user back to the same page with "Sudo has ended." shown. The page itself never asks for sudo. Each credential has a Remove link to a confirm step behind sudo. The password and the last authenticator are kept with a message, and a removed credential signs out every other browser. Each type has a Set up link to its enrollment step behind sudo, where the authenticator app shows a QR code and a key, and a new authenticator takes the place of the old one. The password's link reads Change once one is set, and its step asks for the current password before it takes a new one. `/.well-known/change-password` leads to the page. The `Sessions` section links to a confirm step behind sudo that signs out every other browser, and after an enrollment the page offers the same sign-out as a button next to the status. On the `database` session driver the section also lists each signed-in browser with its device, IP address and last activity, marks this device, and links each other one to a confirm step behind sudo that signs out that browser alone.

## Sub-features

- `security-credentials` the `Password` section says `Set` and the `Authenticator app` section lists one entry, each with a last use from the sign-in that just happened.
- `security-recovery-codes` the `Recovery codes` section says `8 codes left`, and `You're running low on recovery codes.` shows at three or fewer.
- `security-sudo` the `Sudo` section says `Changes won't ask again until` with a time, and has an `End sudo` button.
- `security-end-sudo` `End sudo` lands back on `/settings/security` with `Sudo has ended.` and `Your next change will ask you to confirm it's you.`, and a reload drops the status.
- `security-guest` a guest asking for `/settings/security` is redirected to `/auth/login`.
- `security-remove-refused-second-factor` removing the `Authenticator app` while it is Jane's only second factor shows `You cannot remove your last two-factor credential while two-factor authentication is required.` on the confirm step.
- `security-remove-refused-sign-in` removing the `Password`, Jane's only way to sign in, shows `You cannot remove your only way to sign in.` on the confirm step.
- `security-remove-sudo` the Remove link of a session without sudo goes to `/auth/sudo` and back to the confirm step once sudo is granted.
- `security-enroll-qr` `Set up Authenticator app` opens `/settings/security/enroll/totp` with a QR code that scans to the `otpauth://` link, the Base32 key and the line `the new key replaces the old one`. A reload shows the same key.
- `security-enroll-refused` a code the shown key doesn't make shows `The provided credential is invalid.` and keeps the key.
- `security-enroll` a code from the shown key lands on `/settings/security` with `The credential was replaced. Your other sessions were signed out.`, no `Sign out your other sessions` button, one entry under `Authenticator app`, sudo kept and the credential epoch moved by one.
- `security-enroll-cancel` `Cancel` goes back to the page, and the next `Set up Authenticator app` shows another key.
- `security-sign-out-others` `Sign out other sessions` opens `/settings/security/sessions/others/revoke`, and confirming lands on `/settings/security` with `Your other sessions were signed out.` while another browser signed in as Jane is signed out.
- `security-sign-out-others-offer` after `Set up Password` on an account without a password, the button `Sign out your other sessions` shows under `The new credential was added.`, signs out the other browser without a confirm step and is gone afterwards. It never shows without an enrollment, nor after one that replaced a credential.
- `security-password-change` `Change Password` opens `/settings/security/enroll/password` with the heading `Change password`, `Current password` marked `autocomplete="current-password"` and both new-password fields marked `new-password`. A wrong current password shows `The provided credential is invalid.` under `Current password`, a short new one `The password field must be at least 8 characters.` under `New password`, and the right one lands on `/settings/security` with `Your password was changed. Your other sessions were signed out.` and the credential epoch moved by one.
- `security-password-add` with no password held, `Set up Password` shows `Your account does not have a password set.` and no `Current password`, and a new password lands with `The new credential was added.`, no epoch move, and signs in.
- `security-password-only-kept` removing the password while it is Jane's only way to sign in shows `You cannot remove your only way to sign in.`, and the change step offers no `Remove password` link.
- `security-change-password-url` `/.well-known/change-password` lands a guest on `/auth/login` and Jane on `/settings/security`.
- `security-sessions-list` on the `database` driver the `Sessions` section lists two `li` rows for two browsers signed in as Jane, `This device` on exactly one, the other named by its user agent (`Firefox on Windows`) with a `Sign out Firefox on Windows` link. The page props carry 64-character hex handles and no session id. On the `file` driver it says `Your sessions cannot be listed in this app.`.
- `security-revoke-session` `Sign out Firefox on Windows` opens `/settings/security/sessions/<handle>/revoke` with the heading `Sign out this session?`, and `Sign out` lands on `/settings/security` with `The session was signed out.`, one row left and the other browser on a guest home, while this one stays signed in.
- `security-revoke-current-refused` visiting the confirm step with this device's handle lands on `/settings/security` with `You cannot revoke your current session; sign out instead.`.
- `security-sign-out-others-rows` on the `database` driver, signing out the other sessions leaves one row of Jane's in `sessions`.
- `security-remove` removing a leftover lands on `/settings/security` with `The credential was removed. Your other sessions were signed out.`, the credential gone, and another browser signed in as Jane signed out.

## How to get to it (user POV)

- Choose `Security settings` on home (`/`) while signed in.
- End sudo from home or from the page itself, which lands on the page.
- Choose `Remove <name>` next to a credential on the page, then `Remove` on the confirm step.
- Choose `Set up Authenticator app` on the page, scan the QR code or copy the key, then type a code and choose `Set up`.
- Choose `Change Password` on the page, type the current password and the new one twice, then choose `Change password`. A password manager opens the page from `/.well-known/change-password`.
- Choose `Sign out other sessions` in the `Sessions` section, then `Sign out other sessions` on the confirm step, or `Sign out your other sessions` after an enrollment.
- On the `database` driver, choose `Sign out <device>` next to another session in the `Sessions` section, then `Sign out` on the confirm step.

## Driving it with Playwright

Preconditions:

- `app.sh second-factor <run>` has given Jane a TOTP authenticator and 8 recovery codes.
- Signed in as Jane through the [second-factor challenge](./second-factor-challenge.md), with `You're signed in.` visible on `/`.

- **Open.** `page.getByRole('link', { name: 'Security settings' }).click()`. The heading `Security settings` appears at `/settings/security`.
- **Credentials.** Each section is a `section` holding a heading of its name. In the `Password` section, `getByText('Set', { exact: true })` is visible and `last used never` is absent. The `Authenticator app` section holds one `li` with a last use.
- **Codes and sudo.** The `Recovery codes` section shows `8 codes left`. The `Sudo` section shows `Changes won't ask again until`.
- **End sudo.** In the `Sudo` section, `getByRole('button', { name: 'End sudo' }).click()`. `Sudo has ended.` appears at `/settings/security`, and the `Sudo` section shows `Your next change will ask you to confirm it's you.`.
- **Proof.** `app.sh sql <run> "select type, created_at, last_used_at from user_credentials"` shows a `last_used_at` on the password and on TOTP. `user_security_events` gains `sudo.revoked`.

- **Remove.** `getByRole('link', { name: 'Remove Authenticator app' }).click()` opens the heading `Remove Authenticator app?`. `getByRole('button', { name: 'Remove' }).click()` shows the refusal above the button while Jane holds no other second factor. `Keep it` goes back to the page. A credential to remove for real needs adding first, such as a leftover: `app.sh sql <run> "insert into user_credentials (user_id, type, label, created_at, updated_at) values (1, 'uninstalled', 'Old security key', datetime('now'), datetime('now'))"`.
- **Removal proof.** `user_security_events` gains `credential.removed` with the credential's type and name, `app.sh mail <run>` shows `A sign-in method was removed from your account`, and a second browser signed in as Jane lands on a guest home.

- **Set up.** `getByRole('link', { name: 'Set up Authenticator app' }).click()` opens the heading `Set up Authenticator app`. `getByRole('img', { name: 'QR code holding the key for your authenticator app' })` is the QR code, `page.locator('code').innerText()` the key and the link `Open in authenticator app` the URI. Fill `Code from your authenticator app` with `totp(key)` and click `Set up`. `The new credential was added.` appears at `/settings/security`.
- **Set-up proof.** `user_security_events` gains `credential.replaced` with flow `settings` and type `totp`, since Jane already holds an authenticator, and a wrong code `proof.rejected` with flow `settings` and reason `totp.mismatch`. `select count(*) from user_credentials where type = 'totp'` stays `1`, and `select credential_epoch from users where id = 1` goes up by one for each enrollment.

- **Password.** `getByRole('link', { name: 'Change Password' }).click()` opens the heading `Change password`. Fill `getByLabel('Current password', { exact: true })`, `getByLabel('New password', { exact: true })` and `getByLabel('Confirm new password', { exact: true })`, then click `Change password`. To add a first password, delete Jane's while she is signed in: `app.sh sql <run> "delete from user_credentials where user_id = 1 and type = 'password'"`, then follow `Set up Password`.
- **Password proof.** `user_security_events` gains `proof.rejected` with flow `settings` and reason `password.mismatch` for a wrong current password, `credential.replaced` with flow `settings` and type `password` for the change, and `credential.added` for a first password. `app.sh mail <run>` shows `A sign-in method on your account was changed`.

- **Sign out others.** `getByRole('link', { name: 'Sign out other sessions' }).click()` opens the heading `Sign out other sessions?`. `getByRole('button', { name: 'Sign out other sessions' }).click()` shows `Your other sessions were signed out.` at `/settings/security`. After a set-up, `getByRole('button', { name: 'Sign out your other sessions' })` does the same in one click.
- **Sign-out proof.** `user_security_events` gains `sessions.revoked_others`, `app.sh mail <run>` shows `Your other sessions were signed out`, and a second browser signed in as Jane lands on a guest home. On the `database` driver, `app.sh sql <run> "select count(*) from sessions where user_id = 1"` is `1` afterwards.

- **Sessions list.** Start the run with `SESSION_DRIVER=database`. Sign a second browser in, opened with `open(run, name, { userAgent })` so the list can tell the two apart. On the page, `page.locator('section').filter({ has: page.getByRole('heading', { name: 'Sessions' }) })` holds one `li` per session and `getByText('This device')` once.
- **Revoke one.** In that section, `getByRole('link', { name: 'Sign out Firefox on Windows' }).click()` opens the heading `Sign out this session?`. `getByRole('button', { name: 'Sign out' }).click()` shows `The session was signed out.` at `/settings/security`.
- **Revoke proof.** `user_security_events` gains `session.revoked`, `app.sh mail <run>` shows `One of your sessions was signed out` with the revoked browser's device (`Firefox on Windows`), the other browser lands on a guest home, and its row is gone from `sessions`.

The whole overview path is `scripts/scenarios/security-overview.mjs`: `node .claude/skills/verify/scripts/scenarios/security-overview.mjs <run>`. The removal path, with both refusals, the sudo gate and the other browser, is `scripts/scenarios/credential-removal.mjs`, which adds the leftover itself and spends two recovery codes. The set-up path, with the QR code, a wrong code, two enrollments and a cancel, is `scripts/scenarios/totp-enrollment.mjs`. The sign-out path, through the confirm step and through the offer after a set-up, is `scripts/scenarios/sign-out-others.mjs`, which signs the second browser in twice with recovery codes and deletes Jane's password so that setting one adds a credential. The password path, with the change-password URL, a wrong current password, a weak new password, a change, the refused removal and a first password, is `scripts/scenarios/password-settings.mjs`, which ends signed in with the new password through a recovery code. The sessions list, the refusal of this device, revoking the other browser and signing out the others on the `database` driver is `scripts/scenarios/sessions-list.mjs`, which refuses to run unless the run was started with `SESSION_DRIVER=database`.

## Gotchas

- Times render in the browser's locale, so match on the fixed text around them, never on the time itself.
- The status shows once. A reload of the page after ending sudo no longer shows `Sudo has ended.`.
- The sign-in, the second browser and the sudo replay can't share one TOTP code, so `credential-removal.mjs` answers the second browser's challenge and the sudo replay with recovery codes.
- Jane holds an authenticator once `second-factor` ran, so every set-up in a default run replaces one and moves the epoch. A first authenticator that moves no epoch needs an account signed in without a second factor, which the workbench's mandates never allow. The only credential a default run can add is a password, after deleting Jane's with `app.sh sql`.
- The workbench lists no other sign-in type, so Jane's password is always her only way to sign in and can't be removed there. The feature tests cover removing it while another remains.
- After `totp-enrollment.mjs` the fixture's `totp_key` no longer answers the challenge. Start a fresh run for the next scenario.
- The QR code is scanned with the browser's `BarcodeDetector`. Where the browser has none, the scenario says so and checks the rest.
- The workbench keeps sessions in files unless the run was started with `SESSION_DRIVER=database`. On files the offer always shows after a set-up and the list is unavailable. The offer's check against the `sessions` table, the list and revoking one session need a `database` run.
- The workbench has no IP location driver that knows `127.0.0.1`, so the list shows no location there. The feature tests cover the location.
- The page loaded with `page.goto()` carries its props in `script[data-page="app"]`. After an Inertia click it still holds the first page's, so read the handles right after a `goto`.
- To see `running low`, spend codes first or delete rows with `app.sh sql`, since `second-factor` always gives 8.

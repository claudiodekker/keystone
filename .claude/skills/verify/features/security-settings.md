# Security settings

A signed-in user opens the security page from home. It lists the password and the authenticator app with when each was added and last used, how many recovery codes are left, and until when the session has sudo. Ending sudo there brings the user back to the same page with "Sudo has ended." shown. The page itself never asks for sudo. Each credential has a Remove link to a confirm step behind sudo. The password and the last authenticator are kept with a message, and a removed credential signs out every other browser.

## Sub-features

- `security-credentials` the `Password` section says `Set` and the `Authenticator app` section lists one entry, each with a last use from the sign-in that just happened.
- `security-recovery-codes` the `Recovery codes` section says `8 codes left`, and `You're running low on recovery codes.` shows at three or fewer.
- `security-sudo` the `Sudo` section says `Changes won't ask again until` with a time, and has an `End sudo` button.
- `security-end-sudo` `End sudo` lands back on `/settings/security` with `Sudo has ended.` and `Your next change will ask you to confirm it's you.`, and a reload drops the status.
- `security-guest` a guest asking for `/settings/security` is redirected to `/auth/login`.
- `security-remove-refused-second-factor` removing the `Authenticator app` while it is Jane's only second factor shows `You cannot remove your last two-factor credential while two-factor authentication is required.` on the confirm step.
- `security-remove-refused-sign-in` removing the `Password`, Jane's only way to sign in, shows `You cannot remove your only way to sign in.` on the confirm step.
- `security-remove-sudo` the Remove link of a session without sudo goes to `/auth/sudo` and back to the confirm step once sudo is granted.
- `security-remove` removing a leftover lands on `/settings/security` with `The credential was removed. Your other sessions were signed out.`, the credential gone, and another browser signed in as Jane signed out.

## How to get to it (user POV)

- Choose `Security settings` on home (`/`) while signed in.
- End sudo from home or from the page itself, which lands on the page.
- Choose `Remove <name>` next to a credential on the page, then `Remove` on the confirm step.

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

The whole overview path is `scripts/scenarios/security-overview.mjs`: `node .claude/skills/verify/scripts/scenarios/security-overview.mjs <run>`. The removal path, with both refusals, the sudo gate and the other browser, is `scripts/scenarios/credential-removal.mjs`, which adds the leftover itself and spends two recovery codes.

## Gotchas

- Times render in the browser's locale, so match on the fixed text around them, never on the time itself.
- The status shows once. A reload of the page after ending sudo no longer shows `Sudo has ended.`.
- The sign-in, the second browser and the sudo replay can't share one TOTP code, so `credential-removal.mjs` answers the second browser's challenge and the sudo replay with recovery codes.
- To see `running low`, spend codes first or delete rows with `app.sh sql`, since `second-factor` always gives 8.

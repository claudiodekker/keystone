# Keystone verification map

This directory is the maintained source for verifying Keystone's user-facing behaviour in the workbench. Read this index before driving the app, then use the matching feature file as the recipe. The commands, helpers and handles it names are defined in [`../SKILL.md`](../SKILL.md).

## Baseline preconditions

- Start a run with `.claude/skills/verify/scripts/app.sh start`. The run has its own port, SQLite database and cache, and its database is seeded with Jane (`jane@example.com` / `password`, account id `1`).
- `app.sh doctor <run>` passes with no `FAIL` line.
- For anything past the first factor, `app.sh second-factor <run>` has given Jane a TOTP authenticator and 8 recovery codes.
- Never drive a server this verification run did not start, including the user's own `composer serve`.

## Driving conventions

- Drive pages through a Playwright scenario that imports `scripts/pw.mjs`. Find elements by role and accessible name: labels, button names, headings.
- After every click that submits or navigates, wait for an element the next page renders before you assert or call `step()`.
- Drive operator commands through `app.sh artisan <run> <command>` so they hit the run's database.
- Start each recipe from a fresh run, or from a run whose state you have read back with `app.sh sql`. Rate limits and spent codes carry over within a run.

## Proof and skip reporting

- Capture the action and the result: a `step()` before submitting and another after the redirect lands.
- Read the side effect back. `user_security_events` rows for every flow, `user_recovery_codes` counts for codes, and `app.sh mail <run>` for alerts.
- Operator proof includes the command, its output and its exit code.
- Name the feature file and entry point with every artifact in `.verify/evidence/<run>/`.
- Report a path you could not reach with the command you tried and the precondition that was missing. Never report one entry point as verified through another.

## Feature entry contract

Each feature file starts with an H1 title and one paragraph describing the user-visible behaviour. It then has exactly four H2 sections, in this order.

1. `Sub-features` lists short IDs, one line per behaviour.
2. `How to get to it (user POV)` lists every user entry point.
3. `Driving it with Playwright` (or `with app.sh artisan` for the CLI) starts with `Preconditions:`, then pairs each user action with the exact call and the observable result.
4. `Gotchas` lists traps that can waste or invalidate a run.

## Features

- [Sign in](./sign-in.md) covers the password first factor, a refused credential and a suspended account.
- [Second-factor challenge](./second-factor-challenge.md) covers the hold after the first factor, answering with TOTP, a wrong or replayed code, switching type and cancelling.
- [Recovery codes](./recovery-codes.md) covers answering the challenge with a recovery code, the code being spent, the alert mail and the last code being kept.
- [Sign out](./sign-out.md) covers signing out from home and the status shown afterwards.
- [Sudo](./sudo.md) covers the workbench's page behind sudo, ending sudo, the gate's redirect and the replay through the password and the TOTP code.
- [Security settings](./security-settings.md) covers the security page: the credentials with their last use, the recovery-code count, the sudo end time, ending sudo from it, and removing a credential with both refusals.
- [Operator commands](./operator-commands.md) covers `keystone:end-sessions`, `keystone:suspend` and `keystone:unsuspend` and their effect on a signed-in browser.

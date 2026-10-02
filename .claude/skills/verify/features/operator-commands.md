# Operator commands

An operator acts on an account without its owner from the terminal. They can end its sessions, or suspend it so it can't sign in, and then unsuspend it. Each command records a security event with actor `operator` and alerts the owner unless told not to.

## Sub-features

- `ops-end-sessions` ends every session of one account on its next request and alerts the owner. With `--no-alert`, it sends no alert.
- `ops-end-all` ends every account's sessions with `--all`, logs one event about nobody and alerts nobody.
- `ops-suspend` signs the account out everywhere and refuses its sign-ins until it is unsuspended.
- `ops-unsuspend` lets the account sign in again.
- `ops-refused` fails a suspend of a suspended account, or an unsuspend of an active one, and records nothing.

## How to get to it (user POV)

- `php artisan keystone:end-sessions <id> --operator=<who> [--no-alert]` or `--all [--force]`.
- `php artisan keystone:suspend <id> --operator=<who>` and `keystone:unsuspend <id> --operator=<who>`.

## Driving it with app.sh artisan

Preconditions:

- A fresh run. For the browser effects, a Playwright scenario holds a page signed in as Jane (account id `1`).

- **End sessions.** `app.sh artisan <run> keystone:end-sessions 1 --operator=verify`. Exit code `0`. The signed-in page's next navigation, `page.goto('/')`, shows the guest `Sign in` link. `sessions.terminated` is recorded with actor `operator` and operator `verify`.
- **Suspend.** `app.sh artisan <run> keystone:suspend 1 --operator=verify`. It prints `Suspended account [1].` and exits `0`. A sign-in with the right password is refused with `These credentials do not match our records.`. `account.suspended` is recorded.
- **Refused twice.** Run the same suspend again. It exits non-zero and no new row is added.
- **Unsuspend.** `app.sh artisan <run> keystone:unsuspend 1 --operator=verify`. Signing in works again. `account.unsuspended` is recorded.
- **Alerts.** `app.sh mail <run>` prints one mail per alerting event, such as `Subject: Your account was suspended`.

## Gotchas

- A held sign-in is dropped too, recording `sign_in.voided`, when its account is suspended or its sessions end. Test that from the challenge page.
- `--all` asks for confirmation only in production. The workbench runs as `local`, so it never prompts.
- Unsuspending does not restore the sessions that suspending ended.

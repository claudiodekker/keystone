---
name: verify
description: Launch the Keystone testbench workbench (the Inertia-Vue sign-in, second-factor challenge, recovery-code, sudo, security settings and sign-out pages plus the keystone:* operator commands) on its own port and database, drive it in headless Chromium through Playwright, and capture screenshots, network logs and security-event rows as proof. Use to confirm an auth change works in the real app, to reproduce a sign-in bug on the browser surface, or before opening a PR that changes user-facing behaviour.
---

# Verify Keystone in the workbench

Keystone is a set of Laravel packages, so there is no app of its own. The repo's `workbench/` is the app: `vendor/bin/testbench serve` boots a Laravel skeleton with every package, the Inertia-Vue stubs' pages and routes, and the account `jane@example.com` / `password`. Users touch two surfaces. The browser surface is the auth pages under `/auth/login`. The CLI surface is the `keystone:*` operator commands. Every helper lives in `.claude/skills/verify/scripts/` and runs from anywhere in the checkout.

Feature recipes live in [`features/README.md`](features/README.md). Read the index, then the feature file you are verifying.

## Launch

Once per machine, install the harness. Playwright goes into `~/.cache/keystone-verify`, so the monorepo gains no dependency:

```shell
.claude/skills/verify/scripts/install-playwright.sh
```

Once per checkout, run `composer install && npm install`. The helpers also call `sqlite3`, `lsof` and `curl`, which macOS ships.

Start a run:

```shell
.claude/skills/verify/scripts/app.sh start          # or: app.sh start 8200 to pick the first port tried
# run=20261002-205742-55622 url=http://127.0.0.1:8100 evidence=/…/.verify/evidence/20261002-205742-55622
```

`start` runs `npm run build` when any file under `packages/*/stubs`, `workbench/resources` or `workbench/routes` is newer than the last build, creates `.verify/runs/<run>/database.sqlite`, then runs `migrate:fresh` and seeds Jane into it. It serves on the first free port from 8100 and waits until `GET /auth/login` answers 200. It then runs `doctor` and prints `run=… url=…` only when every check passes. Pass the printed run id to every other command. Every process `app.sh` starts for a run gets `DB_DATABASE`, `APP_URL`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` and `MAIL_MAILER=log` for that run, and never relies on a skeleton `.env`, so the run never touches the workbench's shared database in `vendor/orchestra/testbench-core/laravel/database/database.sqlite`.

The challenge needs a second factor, and enrolling one has no UI yet. Give Jane a fresh TOTP secret and 8 recovery codes:

```shell
.claude/skills/verify/scripts/app.sh second-factor <run>
# {"email":"jane@example.com","password":"password","totp_key":"YQBD…","recovery_codes":["G8K0X-MQ8ZA-…", …]}
```

It replaces any earlier TOTP credential and recovery codes of Jane's, and saves the JSON as `.verify/runs/<run>/second-factor.json` for the driver to read.

## Doctor

Run it first whenever anything looks off. It is read-only:

```shell
.claude/skills/verify/scripts/app.sh doctor <run>
```

It checks that the server process is running, and that the port is held by that process's own `php -S` child rather than someone else's server. It checks that `/auth/login` renders the Inertia component `auth/Login` and that the script the page loads from `/build/assets/` is served. It also checks that Jane exists in the run's database. Any `FAIL` line means you should not drive that run. Stop it and start a fresh one.

## Drive

Write a scenario as a Node script that imports `scripts/pw.mjs`. `open(run, name)` returns a Playwright `page` whose `baseURL` is the run's URL. It also returns `step(label)`, which waits until no request has been in flight for 300 ms (and throws after 15 s), then saves a numbered full-page screenshot and appends the path and first heading to `steps.log`. Every non-asset request goes to `network.log`. `fixture(run)` returns the `second-factor` JSON, and `totp(key)` computes the current code. The working example is `scripts/scenarios/sign-in-with-totp.mjs`:

```shell
node .claude/skills/verify/scripts/scenarios/sign-in-with-totp.mjs <run>
```

Inertia navigates over XHR, so Playwright's load states never reset after a click. Before asserting, wait for something the next page renders, such as `page.getByRole('heading', { name: "Confirm it's you" }).waitFor()`. Never use `waitForLoadState`.

Put new scenarios next to it when they are worth keeping. Put throwaway ones in your scratchpad, with the import pointing at the absolute path of `pw.mjs`.

Use these handles. Each one comes from the stub pages and is stable:

| Page | URL | Handles |
|---|---|---|
| Home | `/` | link `Sign in` (guest), text `You're signed in.` and button `Sign out` (signed in) |
| Sign in | `/auth/login` | heading `Sign in`, label `Email address`, label `Password`, button `Sign in` |
| Challenge | `/auth/login/challenge` | heading `Confirm it's you`, label `Code from your authenticator app` (TOTP), label `Recovery code`, button `Verify`, a button per other type named by type (`recovery-code`), button `Cancel sign-in` |
| Home (signed in) | `/` | link `Security settings`, link `Page behind sudo`, button `End sudo` |
| Security settings | `/settings/security` | heading `Security settings`, a section per type headed `Password`, `Authenticator app` and `Recovery codes`, section `Sudo` with button `End sudo` while sudo lasts, text `Sudo has ended.` after ending sudo; needs no sudo |
| Page behind sudo | `/gated` | heading `A page behind sudo`, link `Home`; behind `['auth', 'sudo']`, workbench only |
| Sudo | `/auth/sudo` | heading `Confirm it's you`, label `Password` then label `Code from your authenticator app`, button `Confirm`, a button per other type named by type |

Errors render as red text under the field they belong to. Assert them with `page.getByText('…')`. The message strings are in `packages/keystone/lang/en/messages.php`.

Drive the CLI surface through the run's database:

```shell
.claude/skills/verify/scripts/app.sh artisan <run> keystone:suspend 1 --operator=verify
```

Jane is account id `1` in a fresh run.

## Evidence

Each scenario writes to `.verify/evidence/<run>/<scenario>/`: the numbered screenshots, `steps.log` and `network.log`. `stop` adds the run's logs and a copy of its `database.sqlite`, so the side effects stay readable after cleanup. Read the side effects from the run's database:

```shell
.claude/skills/verify/scripts/app.sh sql <run> "select id, type, flow, credential_type, reason from user_security_events order by id"
.claude/skills/verify/scripts/app.sh sql <run> "select count(*) from user_recovery_codes"
```

`app.sh` puts each run on the `database` queue and the `log` mailer, so each unsent security alert waits as a row in the run's `jobs` table. Send them and read the mails:

```shell
.claude/skills/verify/scripts/app.sh mail <run>
# To: jane@example.com
# Subject: A recovery code was used on your account
# …  <p>You have 7 recovery codes left.</p>
```

`mail` runs `queue:work --stop-when-empty` against the run. It prints whatever the `log` mailer wrote to the shared `vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log` during the drain and appends it to `.verify/evidence/<run>/mail.log`. That file also gets a `keystone.security_event` line per event, which `mail` leaves out. Read those events from the database instead.

Proof standards:

- Drive the real user path. Click through the pages. Never post to a route the page wouldn't post to, and never call a Keystone class instead of the UI. `second-factor` is the one exception, and only because enrolment has no UI yet.
- Capture the action and the resulting state: a `step()` before submitting and another after the redirect lands, not only the final screen.
- Verify the side effect next to what is visible: the security-event rows, the recovery-code count, the alert in `laravel.log`, the operator command's exit code and output.
- Test the failure path too. A refused answer must show its message and record `proof.rejected` with the matching `reason`.

## Cleanup

```shell
.claude/skills/verify/scripts/app.sh list           # runs in this checkout and whether they are up
.claude/skills/verify/scripts/app.sh stop <run>
```

`stop` kills only the server it started: the pids in `.verify/runs/<run>/server.pid` and `listener.pid` (its `php -S` child), each only while its command line still names the run's port. It copies the run's logs and `database.sqlite` into the evidence directory and deletes `.verify/runs/<run>`. Never kill `php` or `testbench` by name, because the user may be running `composer serve`. Evidence stays in `.verify/evidence/<run>/`, which git ignores. Delete it only when the user asks. Stop a run after a failed scenario too, before you start the next one.

## Isolation

Runs in one checkout can run side by side. Each has its own port, database, cache and rate-limit counters. They share three things in the testbench skeleton under `vendor/orchestra/testbench-core/laravel/`. The first is `public/build`, which every run serves and `start` rebuilds only when a source changed. The second is the file sessions, which are keyed by random ids and so do not collide. The third is `storage/logs/laravel.log`. Do not run `npm run build` against a live run whose assets another agent is relying on. A separate git worktree has its own `vendor/` and so is fully isolated. `testbench serve` copies `.env.example` into `vendor/orchestra/testbench-core/laravel/.env` and leaves it behind when killed. Its `APP_URL=http://localhost:8000` fails the Pest suite's same-origin tests, so `stop` deletes it once no `testbench serve` is left running, unless it differs from `.env.example`. Never run `testbench workbench:build` for verification, because it leaves the same file.

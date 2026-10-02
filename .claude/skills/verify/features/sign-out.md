# Sign out

A signed-in user signs out from home. The session ends and they land on the sign-in page, which reads "You have been logged out."

## Sub-features

- `sign-out` ends the session and shows the status on `/auth/login`.
- `sign-out-history` clears Inertia's encrypted history, so Back doesn't show signed-in pages.

## How to get to it (user POV)

- Choose the `Sign out` button on home (`/`) while signed in.

## Driving it with Playwright

Preconditions:

- Signed in as Jane by any route in [Sign in](./sign-in.md) or [Second-factor challenge](./second-factor-challenge.md), with `You're signed in.` visible on `/`.

- **Sign out.** `page.getByRole('button', { name: 'Sign out' }).click()`. The heading `Sign in` appears at `/auth/login` with `You have been logged out.`. `network.log` shows `POST /auth/logout -> 302`.
- **Signed out for real.** `await page.goto('/')`. The `Sign in` link shows, not `You're signed in.`.
- **Proof.** A `signed_out` row in `user_security_events`.

## Gotchas

- Sign-out lands on `/auth/login`, not on home. Wait for the `Sign in` heading, not the `Sign in` link.
- `Sign out` is an Inertia `Link` rendered as a button. Find it by role `button`.

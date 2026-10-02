# Sign in

A guest signs in at `/auth/login` with an email address and a password. An account without a second factor is signed in and sent to the page it was headed for. An account with one is held for the [challenge](./second-factor-challenge.md). A wrong password, an unknown address and a suspended account are all refused with the same message.

## Sub-features

- `sign-in-password` signs an account without a second factor straight in.
- `sign-in-held` holds an account with a second factor and sends it to the challenge.
- `sign-in-refused` refuses a wrong password with "These credentials do not match our records."
- `sign-in-suspended` refuses a suspended account exactly like a wrong password.
- `sign-in-throttled` refuses further attempts with "Too many attempts. Please try again in N seconds." once the failed-attempt limit is hit.

## How to get to it (user POV)

- Choose the `Sign in` link on home (`/`) as a guest.
- Open `/auth/login` directly.

## Driving it with Playwright

Preconditions:

- A fresh run that passes `app.sh doctor`. For `sign-in-held`, `app.sh second-factor <run>` has been run.

- **Open the form.** `await page.goto('/')` then `page.getByRole('link', { name: 'Sign in' }).click()`. The heading `Sign in` appears at `/auth/login`.
- **Sign in.** `page.getByLabel('Email address').fill('jane@example.com')`, `page.getByLabel('Password').fill('password')`, `page.getByRole('button', { name: 'Sign in' }).click()`. Without a second factor, `You're signed in.` appears on `/` and `signed_in` is recorded with flow `sign-in` and credential type `password`. With one, the heading `Confirm it's you` appears at `/auth/login/challenge` and `sign_in.held` is recorded with reason `keystone.challenge`.
- **Refused.** Submit `wrong` as the password. The page stays on `/auth/login` and shows `These credentials do not match our records.` under the email field. `proof.rejected` is recorded with reason `password.mismatch`.
- **Suspended.** Run `app.sh artisan <run> keystone:suspend 1 --operator=verify`, then sign in with the right password. The page shows the same refusal as a wrong password.
- **Proof.** `app.sh sql <run> "select type, flow, credential_type, reason from user_security_events order by id"`.

## Gotchas

- The network log shows the form posting to `/auth/login/password`, named after the credential type, not to `/auth/login`.
- A refusal redirects back with a 302 and re-renders the page. Wait for the error text, not for a URL change.
- Failed attempts count per account in the run's database cache and carry across scenarios in the same run. Start a fresh run before testing throttling boundaries.

# The second-factor challenge

An account that holds a second factor, such as a TOTP authenticator (see [TOTP](totp.md)), signs in in two steps. The first factor, such as a password, doesn't sign it in: Keystone holds the sign-in as a pending sign-in and sends the user to the challenge page, where they answer with their second factor. Only then is the session signed in.

A first factor that proves more than one factor on its own, such as a passkey that verified its user, skips the challenge.

## The pending sign-in

While a sign-in is held, the session is a guest everywhere but the challenge: `auth` middleware refuses it and `Auth::user()` is null. A session holds at most one; passing another account's first factor replaces it. It lasts 15 minutes from the first factor, however busy the user is, after which the challenge page sends them back to sign in.

A pending sign-in is dropped, and `sign_in.voided` recorded, when in the meantime its account is suspended or soft-deleted, or its sessions are ended, such as by a password change or `keystone:end-sessions`.

Every time a session is held, signed in, cancelled or dropped, its id is rotated and whatever a method was in the middle of (a ceremony slot, such as a challenge a security key must sign) is thrown away.

## The challenge page

The page offers every second-factor type the account holds that `keystone.methods` lists on the `challenge` surface, leaving out the first factor's type, so a password can't answer for a password. The first type offered is preselected.

When the account holds a second factor but no listed type can answer it, for example after you removed a method from `keystone.methods`, the pending sign-in is kept and the user is sent on with "Your second factor is no longer available. Recover your account to sign in again." Until account recovery has its own page, the Inertia-Vue adapter shows that on the sign-in page. When the account no longer holds a second factor at all, the pending sign-in is dropped and the user is sent back to sign in.

Only a credential of another type than the first factor's counts as a second factor. A second factor keeps counting after you unlist its type: an account with one still owes the challenge, so taking a method out of `keystone.methods` never lets its users skip it.

## Answering

A wrong answer is refused with "The provided credential is invalid." on the type's field, counts one failed attempt against the account for that type, and records `proof.rejected` with flow `challenge`. Nothing typed is flashed back. A correct answer signs the session in, records `signed_in` with flow `challenge` and the answering credential, and sends the user on to the page they were headed for before the first factor. Input that fails the type's validation rules is sent back to the challenge page with its errors, without counting a wrong answer or recording anything.

## Cancelling

The page's cancel button (`DELETE` to `login.challenge.cancel`) drops the pending sign-in and sends the user to sign in again, reading "Sign-in cancelled — you were not logged in." Cancelling resets no rate limit.

## Changing the responses

The adapter's `ChallengeController` has one hook per outcome: `sendChallengePage`, `sendSecondFactorUnavailable`, `sendChallengeRefused`, `sendChallengePassed` and `sendChallengeCancelled`. The sign-in controller's `sendChallengeOwed` sends a pending sign-in to the challenge. If you change one, redefine its assertion in `tests/Keystone/Assertions/ChallengeAssertions.php` (or `SignInAssertions.php`), so Keystone's AppTests check your response instead.

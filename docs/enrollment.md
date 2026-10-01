# Enrollment

By default every account must hold a second factor and a set of recovery codes. An account that doesn't is held at sign-in until it enrolls them: its first factor doesn't sign it in, the user is sent to set up a second factor, then to save recovery codes, and only then is the session signed in.

## The mandates

| Setting | Default | An account owes enrollment when |
|---|---|---|
| `require_second_factor` | `true` | it holds no second factor and its first factor doesn't prove two factors on its own, as a passkey that verified its user does |
| `require_recovery_codes` | `true` | it holds no recovery code |

An account that holds a second factor answers the [challenge](challenge.md) first and enrolls whatever it still owes after. Turning `require_recovery_codes` off means codes are never owed, and also lets the last code answer the challenge (see [Recovery codes](challenge.md#recovery-codes)).

In production, Keystone refuses to boot while `require_second_factor` is on and no type listed in `keystone.methods` can be enrolled as a second factor: one that serves `enrollment` and `challenge`, such as [TOTP](totp.md), or one that proves two factors on its own. See [Configuration](configuration.md#boot-checks).

## The held sign-in

While enrollment is owed the session is a guest, as during the challenge (see [The pending sign-in](challenge.md#the-pending-sign-in)): it lasts 15 minutes from the first factor, and is dropped when the account is suspended or its sessions are ended. Holding it records `sign_in.held` with the reason `keystone.enrollment`.

If the account gains a second factor elsewhere before this one is enrolled, the held sign-in is dropped and the user signs in again, this time answering the challenge.

## Choosing a second factor

The enrollment page (`login.enrollment`) lists every listed type that serves `enrollment` and either answers the challenge or proves two factors on its own, with its shape, and preselects the first. A password is never offered: it is a first factor only.

Choosing a type (`login.enrollment.start`) starts its ceremony, such as TOTP making a new key, and shows what the user needs, such as the key and the link that adds it to an authenticator app. Reloading shows the same ceremony. The ceremony lasts as long as the held sign-in.

## Answering

A correct answer (`login.enrollment.submit`) stores the new credential and records `credential.added` with flow `enrollment` in one change, which alerts the account's owner (see [Security alerts](security-alerts.md)) and ends no other session. The user then goes on to recovery codes, or is signed in when none are owed, recording `signed_in` with flow `enrollment`.

A wrong answer stores nothing, is refused with "The provided credential is invalid." on the type's field, and records `proof.rejected` with flow `enrollment`. Nothing typed is flashed back. An answer sent again after it was accepted enrolls nothing twice.

An answer that arrives when no ceremony is running, for example after the user left the page open, sends the user back to the type's form with a new ceremony, reading "Your enrollment session expired. Please start again."

## Recovery codes

The recovery-codes page (`login.recovery-codes`) shows a new set of 8 codes. The set is kept in the session until it is saved, so a reload shows the same codes. To save them, the user types one back (`login.recovery-codes.submit`, field `code`): that stores the set, records `recovery_codes.generated` with flow `enrollment`, and signs the session in. A first set alerts nobody. A code that isn't one of the set is refused with "The recovery code you entered is incorrect." and saves nothing; nothing typed is flashed back.

## Cancelling

The cancel button (`DELETE` to `login.enrollment.cancel`) drops the held sign-in and sends the user to sign in again, reading "Two-factor setup cancelled. You were not logged in." The account still owes enrollment at its next sign-in.

## Signed-in sessions that newly owe

When an account that is signed in comes to owe enrollment, for example because you turned a mandate on, Keystone holds its session at enrollment the next time something asks who is signed in. The session id is rotated and any ceremony is thrown away, but its site data is kept, and `sign_in.held` is recorded with the reason `demoted`. When your `auth` middleware then refuses the request:

- a browser is redirected to the enrollment page, and goes on to the page it asked for once enrolled;
- a request expecting JSON gets a `403` with `{"message": "Finish setting up two-factor authentication to continue.", "reason": "demoted"}`.

To answer your own way, bind your own `ClaudioDekker\Keystone\Actions\RespondToDemotedSession` and override its `handle()`, as for an [expired session](configuration.md#session-lifetime), then redefine `assertDemotedToEnrollment()` or `assertDemotedJsonRefused()` in `tests/Keystone/Assertions/EnrollmentAssertions.php`.

## Changing the responses

The adapter's `EnrollmentController` has one hook per outcome: `sendEnrollmentPage`, `sendEnrollmentForm`, `sendEnrollmentNotStarted`, `sendEnrollmentRefused`, `sendEnrollmentExpired`, `sendRecoveryCodesOwed`, `sendEnrollmentCompleted` and `sendEnrollmentCancelled`. The `RecoveryCodesController` has `sendRecoveryCodesPage`, `sendRecoveryCodeRefused`, `sendSecondFactorOwed` and `sendRecoveryCodesSaved`. The sign-in controller's `sendEnrollmentOwed` and the challenge controller's `sendEnrollmentOwedAfterChallenge` send a held sign-in to enrollment. If you change one, redefine its assertion, named after the hook, in `tests/Keystone/Assertions/EnrollmentAssertions.php` (or `RecoveryCodesAssertions.php`, `SignInAssertions.php`, `ChallengeAssertions.php`), so Keystone's AppTests check your response instead.

## Credential types

A type serves enrollment by listing the `enrollment` surface and implementing `initiate()`, which returns an `Initiation`: what core keeps of the ceremony in the session, and the strings the page shows. `verify()` on `enrollment` gets that ceremony back and returns `Proof::enrolled()` with the credential to store, or a rejected proof. Its test support's `validEnrollment()` and `rejectedEnrollment()` answer a ceremony for the AppTests.

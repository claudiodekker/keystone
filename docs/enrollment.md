# Enrollment

By default every account must hold a second factor and a set of recovery codes. An account that doesn't is held at sign-in until it enrolls them: its first factor doesn't sign it in, the user is sent to set up a second factor, then to save recovery codes, and only then is the session signed in.

## The mandates

| Setting | Default | An account owes enrollment when |
|---|---|---|
| `require_second_factor` | `true` | it holds no credential of a listed type that answers the [challenge](challenge.md). A first factor that proves two factors on its own, as a passkey that verified its user does, counts when its type answers the challenge, and is then not asked for again; a type that only signs in never counts, so its holders enroll a second factor like anyone else |
| `require_recovery_codes` | `true` | it holds no recovery code |

An account that holds a second factor answers the [challenge](challenge.md) first and enrolls whatever it still owes after. Turning `require_recovery_codes` off means codes are never owed, and also lets the last code answer the challenge (see [Recovery codes](challenge.md#recovery-codes)).

In production, Keystone refuses to boot while `require_second_factor` is on and no type listed in `keystone.methods` can be enrolled as a second factor: one that serves `enrollment` and `challenge`, such as [TOTP](totp.md). See [Configuration](configuration.md#boot-checks).

## The held sign-in

While enrollment is owed the session is a guest, as during the challenge (see [The pending sign-in](challenge.md#the-pending-sign-in)): it lasts 15 minutes from the first factor, and is dropped when the account is suspended or its sessions are ended. Holding it records `sign_in.held` with the reason `keystone.enrollment`.

If the account gains a second factor elsewhere before this one is enrolled, even while its recovery codes are being saved, the held sign-in is dropped and the user signs in again, this time answering the challenge. A mandate turned off while a sign-in is held for it drops the held sign-in the same way, and the user signs in again.

## Choosing a second factor

The enrollment page (`login.enrollment`) lists every listed type that serves `enrollment` and answers the challenge, with its shape. A password is never offered: it is a first factor only.

Choosing a type (`login.enrollment.start`) starts its ceremony, such as TOTP making a new key, and shows what the user needs, such as the QR code and the key that add it to an authenticator app. Reloading shows the same ceremony. The ceremony lasts as long as the held sign-in.

A signed-in user adds a credential from the [security settings](security-settings.md#adding-a-credential) instead, through the same ceremony and the same form.

## Answering

A correct answer (`login.enrollment.submit`) stores the new credential and records `credential.added` with flow `enrollment` in one change, which alerts the account's owner (see [Security alerts](security-alerts.md)) and ends no other session. The user then goes on to recovery codes, or is signed in when none are owed, recording `signed_in` with flow `enrollment`.

A wrong answer stores nothing, is refused with "The provided credential is invalid." on the type's field, and records `proof.rejected` with flow `enrollment`. Nothing typed is flashed back. An answer sent again after it was accepted enrolls nothing twice.

An answer that arrives once the account holds a second factor after all, for example because the same answer was sent twice at once or another sign-in enrolled one first, stores nothing and is refused the same way, recording `proof.rejected` with the reason `keystone.second_factor_held`. An answer that arrives once the account's sessions were ended, such as by a password change elsewhere, stores nothing either, recording the reason `keystone.superseded`, and the held sign-in is dropped on the next request. An answer whose account was suspended in the meantime is refused the same way, recording the reason `keystone.barred`.

An answer that arrives when no ceremony is running, for example after the user left the page open, sends the user back to the type's form with a new ceremony, reading "Your enrollment session expired. Please start again."

## Recovery codes

The recovery-codes page (`login.recovery-codes`) shows a new set of 8 codes. The set is kept in the session until it is saved, so a reload shows the same codes. To save them, the user types one back (`login.recovery-codes.submit`, field `code`): that stores the set, records `recovery_codes.generated` with flow `enrollment`, and signs the session in. A first set alerts nobody. If the account saved a set elsewhere in the meantime, this one is refused, recording `proof.rejected` with the reason `keystone.recovery_codes_held`, and the saved set is kept. A code typed back once the account was suspended is refused too, recording the reason `keystone.barred`. A code that isn't one of the set is refused with "The recovery code you entered is incorrect." and saves nothing; nothing typed is flashed back.

## Cancelling

The cancel button (`DELETE` to `login.enrollment.cancel`) drops the held sign-in and sends the user to sign in again, reading "Two-factor setup cancelled. You were not logged in." The account still owes enrollment at its next sign-in.

## Signed-in sessions that newly owe

When an account that is signed in comes to owe enrollment, for example because you turned a mandate on or removed its second factor, Keystone ends its session the next time something asks who is signed in, as it does when a session [expires](configuration.md#session-lifetime): the session is invalidated, `session.ended` is recorded with the reason `demoted`, and `Clear-Site-Data` is sent. A session never enrolls anything on the strength of an earlier sign-in. When your `auth` middleware then refuses the request:

- a browser is redirected to sign in, or wherever your `redirectGuestsTo` sends guests, and the sign-in page's `status` reads "Please sign in again to finish setting up two-factor authentication."; the page it asked for is kept, so it lands there once signed in again;
- a request expecting JSON gets a `401` with `{"message": "Please sign in again to finish setting up two-factor authentication.", "reason": "demoted"}`.

Signing in again proves the account afresh: the first factor, then the challenge when the account holds a second factor, then the enrollment it owes. Signing out of such a session ends it the same way.

A session ended this way forgets the [remember-me](remember-me.md#what-ends-it) token of its browser, and a remember-me cookie of an account that owes enrollment restores nothing: the user gets the same redirect or `401` and signs in again.

To answer your own way, bind your own `ClaudioDekker\Keystone\Actions\RespondToDemotedSession` and override its `handle()`, exactly as for an [expired session](configuration.md#session-lifetime), then redefine `assertDemotedToSignIn()` or `assertDemotedJsonRefused()` in `tests/Keystone/Assertions/EnrollmentAssertions.php`.

## Changing the responses

The adapter's `EnrollmentController` has one hook per outcome: `sendEnrollmentPage`, `sendEnrollmentForm`, `sendEnrollmentNotStarted`, `sendEnrollmentRefused`, `sendEnrollmentExpired`, `sendRecoveryCodesOwed`, `sendEnrollmentCompleted` and `sendEnrollmentCancelled`. The `RecoveryCodesController` has `sendRecoveryCodesPage`, `sendRecoveryCodeRefused`, `sendSecondFactorOwed` and `sendRecoveryCodesSaved`. The sign-in controller's `sendEnrollmentOwed` and the challenge controller's `sendEnrollmentOwedAfterChallenge` send a held sign-in to enrollment. If you change one, redefine its assertion, named after the hook, in `tests/Keystone/Assertions/EnrollmentAssertions.php` (or `RecoveryCodesAssertions.php`, `SignInAssertions.php`, `ChallengeAssertions.php`), so Keystone's AppTests check your response instead.

## Credential types

A type serves enrollment by listing the `enrollment` surface and implementing `initiate()`, which returns an `Initiation`: what core keeps of the ceremony in the session, and the strings the page shows. A type with no ceremony returns `null`. The page's strings are kept in the session, so keep them small. A type whose form shows something large that it can draw from those strings, as TOTP draws its QR code from the link, also implements `PresentsCeremony`: `present()` receives the kept page on every request and returns the page the form shows. `verify()` on `enrollment` gets that ceremony back and returns `Proof::enrolled()` with the credential to store, or a rejected proof. Its test support's `validEnrollment()` and `rejectedEnrollment()` answer a ceremony for the AppTests, and `validProofOfEnrolled()` answers the next challenge with the credential that ceremony enrolled.

# The second-factor challenge

An account that holds a second factor, such as a TOTP authenticator (see [TOTP](totp.md)), signs in in two steps. The first factor, such as a password, doesn't sign it in: Keystone holds the sign-in as a pending sign-in and sends the user to the challenge page, where they answer with their second factor. Only then is the session signed in.

A first factor that proves more than one factor on its own, such as a passkey that verified its user, skips the challenge.

## The pending sign-in

While a sign-in is held, the session is a guest everywhere but the step it is held at, the challenge or an [enrollment](enrollment.md): `auth` middleware refuses it and `Auth::user()` is null. A session holds at most one; passing another account's first factor replaces it. It lasts 15 minutes from the first factor, however busy the user is, after which the challenge page sends them back to sign in.

A pending sign-in is dropped, and `sign_in.voided` recorded, when in the meantime its account is suspended or soft-deleted, or its sessions are ended, such as by a password change or `keystone:end-sessions`.

Every time a session is held, signed in, cancelled or dropped, its id is rotated and whatever a method was in the middle of (a ceremony slot, such as a challenge a security key must sign) is thrown away.

## The challenge page

The page offers every second-factor type the account holds that `keystone.methods` lists on the `challenge` surface, leaving out the first factor's type, so a password can't answer for a password, and then [recovery codes](#recovery-codes) while the account holds any. The first type offered is preselected.

When the account holds no second factor a listed type can answer, the pending sign-in is dropped and the user is sent back to sign in.

Only a credential of a type that `keystone.methods` lists on the `challenge` surface, and of another type than the first factor's, counts as a second factor. A credential of a type that proves two factors on its own, such as a passkey, counts when its type serves `challenge`: a password sign-in of an account holding one is challenged, and the passkey answers. When you unlist a type, its credentials stop counting but stay stored: its users are no longer challenged for them, and with `require_second_factor` on they enroll a second factor again. Listing the type again brings the credentials back.

## Answering

A wrong answer is refused with "The provided credential is invalid." on the type's field, counts one failed attempt against the account for that type, and records `proof.rejected` with flow `challenge`. Nothing typed is flashed back. A correct answer signs the session in, stamps the answering credential's last use (shown on the [security page](security-settings.md)), records `signed_in` with flow `challenge` and the answering credential, and sends the user on to the page they were headed for before the first factor. When the account still owes [enrollment](enrollment.md), such as recovery codes, the answer instead moves the held sign-in on to it and records `sign_in.held` with the reason `keystone.enrollment`. Input that fails the type's validation rules is sent back to the challenge page with its errors, without counting a wrong answer or recording anything.

## Recovery codes

Recovery codes are core's own second factor, for a user who lost their usual one. The challenge offers them, after the account's other second factors, whenever the account holds at least one. They answer only the challenge: never a sign-in, and they never count as the second factor an account must hold. A code is submitted as type `recovery-code` with its value in `code`.

A set is 8 codes of 26 characters from A to Z and 0 to 9, drawn by a cryptographically secure generator and shown in dash-separated blocks of 5, such as `K7Q2M-ZP4XD-9WB3N-HT6RC-E8YJA-5`. Users may type a code in any case, with or without its dashes, and with spaces. Keystone stores only the SHA-256 digest of each code, so a database leak doesn't give the codes away and rotating `APP_KEY` leaves them working. A code imported from Fortify, two blocks of 10 letters and digits, matches only exactly as Fortify issued it, case and dash included.

Each code works once: a correct one is deleted as it answers, so two submissions of one code sign in only once. Its use records `recovery_code.used` and mails the account's owner how many codes are left (see [Security alerts](security-alerts.md)). A wrong code is refused like any wrong answer and counts against recovery codes' own failed-attempt limit.

While `require_recovery_codes` is on, as it is by default, the account's last code is refused and kept, with "This is your last recovery code. It is kept for account recovery and cannot be used here." The refusal counts as a failed attempt. Turn the setting off to let the last code answer too.

## Cancelling

The page's cancel button (`DELETE` to `login.challenge.cancel`) drops the pending sign-in and sends the user to sign in again, reading "Sign-in cancelled. You were not logged in." Cancelling resets no rate limit, and doesn't stop the [abandoned-challenge alert](security-alerts.md#abandoned-challenges) a sign-in from an unknown browser sends.

## Changing the responses

The adapter's `ChallengeController` has one hook per outcome: `sendChallengePage`, `sendChallengeRefused`, `sendChallengePassed` and `sendChallengeCancelled`. The sign-in controller's `sendChallengeOwed` sends a pending sign-in to the challenge, and `sendEnrollmentOwedAfterChallenge` sends a passed challenge on to [enrollment](enrollment.md). A refused last recovery code goes through `sendChallengeRefused` too, checked by `assertLastRecoveryCodeKept`. If you change one, redefine its assertion in `tests/Keystone/Assertions/ChallengeAssertions.php` (or `SignInAssertions.php`), so Keystone's AppTests check your response instead.

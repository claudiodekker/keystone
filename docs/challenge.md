# Challenge

When an account holds a second factor, a right first factor doesn't sign the user in. Keystone holds the sign-in and asks for the second factor on the challenge page. The user signs in once they answer it, or goes back to sign in when they cancel.

## When a sign-in is held

A second factor is a credential of a type offered on the `challenge` surface, such as TOTP. A first factor that doesn't prove two factors on its own, such as a password, is held when the account holds one. Keystone reads what the account holds from its credentials on every step and never stores it, so taking a type off the challenge in [`methods`](configuration.md#choosing-the-methods) stops its credentials counting, and listing it again makes them count once more. The credentials stay stored either way.

A held sign-in is the session's pending sign-in. It keeps the account, the type of the first factor, the page the user was going to and the account's credential epoch, and it lasts 15 minutes from the hold. There is no setting for that. A session holds one pending sign-in at most, so passing a first factor again replaces it. To every other page the session is a guest, so a page behind `auth` sends it to sign in.

Holding a sign-in, completing it and cancelling it each give the session a new id.

## The routes

```php
use App\Http\Controllers\Auth\ChallengeController;

Route::prefix('login/challenge')->group(function () {
    Route::get('/', [ChallengeController::class, 'show'])->name('login.challenge');
    Route::post('{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
    Route::delete('/', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
});
```

The Inertia and Vue installer adds these routes, the controller, the `auth/Challenge` page and a partial for each installed type that serves the challenge, plus one for recovery codes.

## What the user can do next

The page offers the second-factor types the account holds, apart from the first factor's type, then [recovery codes](#recovery-codes) when the account holds any, with the first one preselected. The page value carries `types`, each with its `name` and `shape`, and `preselected`, the name of the type the page opens on. The page runs inside the 300 ms timing floor.

An answer posts the type's own fields to `login.challenge.submit` for that type. Keystone decides again, as the account stands at that moment, whether it still owes the challenge, both when the page is shown and when an answer arrives:

- A right answer signs the user in and sends them to the page they were going to before the first factor, or `/`. While the account still owes recovery codes, it moves on to [enrollment](enrollment.md) instead.
- A wrong answer is refused with "The provided credential is invalid." on the type's field, and the pending sign-in stays. Nothing the user typed is flashed back.
- An answer of the first factor's type, or one that proves another account's credential, is refused the same way.
- A pending sign-in whose account's credential epoch moved since the hold, such as when an operator ended its sessions or suspended it, is dropped and recorded as `sign_in.voided`.
- A pending sign-in whose account no longer holds a second factor is dropped.
- A session without a pending sign-in, or whose pending sign-in was dropped, is sent to sign in.

## Recovery codes

Recovery codes are Keystone's own type, `recovery-code`. They answer the challenge for a user who has lost their second factor, and they are never listed in [`methods`](configuration.md#choosing-the-methods). The page offers them after the account's other second factors whenever it holds at least one unspent code. Codes never count as a second factor, so an account that holds codes and no second factor is never held at the challenge. While a second factor is required, it is held at [enrollment](enrollment.md) instead.

A set is 8 codes of 26 characters from A to Z and 0 to 9, shown in dash-separated blocks of 5, such as `7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8`. The user may type a code in any case, with or without its dashes, and with spaces. Keystone stores each code as the SHA-256 digest of its characters in upper case, never the code itself. A code Laravel Fortify generated, such as `aB3dE6gH9j-K1mN3pQ5sT`, matches only exactly as Fortify showed it.

An answer posts `recovery_code` to `login.challenge.submit` for `recovery-code`:

- A code the account holds signs the user in and is spent, so it never answers again. Keystone records `recovery_code.used` and mails the owner an alert that says how many codes are left. Two answers of one code at the same moment spend it once, and only one of them signs in.
- A code the account doesn't hold is refused like any wrong answer, on the `recovery_code` field.
- While `require_recovery_codes` is `true`, the default, the account's last code is refused and kept with "This is your last recovery code. It is kept for account recovery and cannot be used here." Its failed attempt stays counted. With `require_recovery_codes` set to `false`, the last code is spent like any other.

Recovery codes have their own failed-attempt count, apart from every other type's, so wrong answers of the other second factor never lock the user out of their codes.

Cancelling sends a `DELETE` to `login.challenge.cancel`. It drops the pending sign-in and lands on the sign-in page with "Sign-in cancelled. You were not logged in." Cancelling leaves every rate limit count alone, so starting over resumes the same failed-attempt budget. It also leaves the [abandoned-challenge alert](security-events.md#abandoned-challenges) in place, so a browser the account doesn't know that cancels still mails the owner 7 minutes after its hold.

## Your controller

Your `ChallengeController` extends Keystone's and decides what each outcome looks like. Each outcome leaves through one method:

| Method | Outcome |
|---|---|
| `sendChallengePage(Request $request, ChallengePage $page)` | The challenge page. |
| `sendChallengeRefused(Request $request, string $field, string $message)` | A refused answer, with the message for the field. |
| `sendLastRecoveryCodeKept(Request $request, string $field, string $message)` | The account's last recovery code, refused and kept, with the message for the field. |
| `sendSignedIn(Request $request, string $redirectTo)` | A completed sign-in. |
| `sendNoPendingSignIn(Request $request)` | A session that holds no pending sign-in. |
| `sendChallengeCancelled(Request $request)` | A cancelled sign-in. |

Your `SignInController` sends a held sign-in on through `sendHeldForChallenge(Request $request)`. Both controllers send a sign-in that still owes enrollment on through `sendHeldForEnrollment(Request $request)`.

## Rate limits

The page counts as a `view`, an answer as a `submit` and cancelling as a `change`, per IP address and per held account. A wrong answer takes a failed attempt of the type in the `challenge` flow, apart from the sign-in flow's count. See [rate limits](security.md#rate-limits).

## Testing

The AppTests check each outcome through an assertion you can redefine in your app's `ChallengeAssertions` trait: `assertChallengePage()`, `assertChallengeRefused()`, `assertChallengeInvalid()`, `assertLastRecoveryCodeKept()`, `assertNoPendingSignIn()` and `assertChallengeCancelled()`, plus `assertHeldForChallenge()`, `assertHeldForEnrollment()` and `assertSignedIn()` from `SignInAssertions`.

Two helpers walk a user through the steps your own tests repeat. `passFirstFactor()` gives a user a verified address and a credential of the first installed type that serves sign-in, then signs in with it. `answerChallenge()` answers the challenge with the user's credential of a type's test support:

```php
use ClaudioDekker\Keystone\Methods\Surface;

$user = $this->createUser();
$support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);

$this->assertHeldForChallenge($this->passFirstFactor($user));
$this->assertSignedIn($this->answerChallenge($user, $support), '/');
```

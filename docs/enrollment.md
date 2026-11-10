# Enrollment

By default every account must hold a second factor and recovery codes. A sign-in of an account that lacks either is held at enrollment after its first factor, and the user signs in only once they have enrolled a second factor and saved a set of recovery codes. A signed-in session whose account comes to lack either is signed out, and the user signs in again and enrolls.

## When a sign-in is held at enrollment

Two settings in `config/keystone.php` decide what an account owes, and both are `true` by default:

```php
'require_recovery_codes' => true,

'require_second_factor' => true,
```

While `require_second_factor` is `true`, an account owes a second factor until it holds a credential of a type offered on the [challenge](challenge.md), such as TOTP. While `require_recovery_codes` is `true`, it owes recovery codes until it holds an unspent one. With both set to `false`, nothing is ever owed.

A first factor is held at the challenge when the account holds a second factor, and an answer there moves on to enrollment while the account still owes its codes. Otherwise a first factor whose account owes something is held at enrollment straight away. Keystone reads what the account holds from its credentials and codes, and never stores it. It decides again, as the account stands at that moment, each time the enrollment page is shown and each time an answer arrives:

- A pending sign-in whose account's credential epoch moved since the hold, such as when an operator ended its sessions or suspended it, is dropped and recorded as `sign_in.voided`.
- A pending sign-in whose account no longer owes anything is dropped.
- A pending sign-in whose account holds a second factor that this sign-in neither answered at the challenge nor enrolled itself, such as one added from another browser, is dropped. The user signs in again and answers the challenge for it.
- A session without a pending sign-in at enrollment, or whose pending sign-in was dropped, is sent to sign in.

The pending sign-in lasts 15 minutes from its first hold, and to every other page the session is a guest. Moving on from the challenge to enrollment, enrolling a second factor, signing in and cancelling each give the session a new id.

## Signed-in sessions that newly owe

A session never enrolls anything on the strength of an earlier sign-in. When a request finds that the signed-in account owes something, because a mandate was turned on or its second factor was removed, Keystone ends the session as it ends an expired one. It clears the browser's site data, records `session.ended` with the reason `keystone.demoted` and leaves "Please sign in again to finish setting up two-factor authentication." for the sign-in page.

A request that needs a signed-in user then gets your app's `SessionDemotedResponse`. By default a browser is sent to sign in, with the page it asked for kept as the intended URL, and a JSON client gets a 401 with the reason `demoted`:

```json
{
    "message": "Please sign in again to finish setting up two-factor authentication.",
    "reason": "demoted"
}
```

Bind your own response to answer differently:

```php
use ClaudioDekker\Keystone\Contracts\SessionDemotedResponse;

$this->app->bind(SessionDemotedResponse::class, YourDemotedResponse::class);
```

While a mandate is on, every request that reads the signed-in user asks the database whether the account holds a second factor and recovery codes: up to two indexed lookups, once per request. With both mandates off, it asks nothing.

## The routes

```php
use App\Http\Controllers\Auth\EnrollmentController;

Route::prefix('login/enrollment')->group(function () {
    Route::get('/', [EnrollmentController::class, 'show'])->name('login.enrollment');
    Route::post('{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
    Route::delete('/', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');
});
```

The Inertia and Vue installer adds these routes, the controller, the `auth/Enrollment` and `auth/RecoveryCodes` pages and an enrollment partial for each installed type that can be enrolled.

## Enrolling a second factor

While the account owes a second factor, the page offers every type that serves enrollment and is also offered on the challenge, with the first one preselected. A type the challenge doesn't offer is never offered here, since the credential it made couldn't answer a later challenge. The page value carries `types`, each with its `name`, its `shape` and its `ceremony`, and `preselected`, the name of the type the page opens on.

Showing the page starts each offered type's ceremony when it has none running, such as a new TOTP key, and `ceremony` holds what the page shows of it. A reload shows the same ceremony until it ends, 10 minutes after its start or with the pending sign-in when that comes first.

An answer posts the type's own fields to `login.enrollment.submit` for that type:

- A right answer stores the new credential and records `credential.added`, which alerts the account's owner, in one account change that moves no credential epoch. The user then moves on to save their recovery codes, or signs in when the account owes none.
- A wrong answer is refused with "The provided credential is invalid." on the type's field. Nothing is stored, the ceremony stays, and nothing the user typed is flashed back.
- An answer after the ceremony ended is refused with "Your enrollment session expired. Please try again." on the type's field, and a new ceremony starts.
- Two answers at the same moment store one credential: the one that loses the race is refused and stores nothing. So is an answer whose pending sign-in left the account's credential epoch while it was checked.

## Saving recovery codes

Once the account holds a second factor, or when only codes are owed, the page shows a new set of recovery codes instead. The set lives in the session until the user types one back, so a reload shows the same codes and nothing is stored that the user never saw. The page value carries `codes`, the 8 codes as they are shown.

An answer posts `recovery_code` to `login.enrollment.submit` for `recovery-code`:

- One of the shown codes stores the whole set, as digests, and records `recovery_codes.generated` with no alert for a first set, in one account change. The user then signs in and lands on the page they were going to before the first factor, or `/`.
- Any other code is refused with "The recovery code you entered is incorrect." on the `recovery_code` field, and nothing is stored.
- An answer after the set ended, 10 minutes after it was shown, is refused with the expired message, and a new set is shown.
- Two answers at the same moment store one set: the one that loses the race is refused and stores nothing.

The user can type the code in any case, with or without its dashes, as at the [challenge](challenge.md#recovery-codes).

## Cancelling

Cancelling sends a `DELETE` to `login.enrollment.cancel`. It drops the pending sign-in, with its ceremonies and staged codes, and lands on the sign-in page with "Two-factor setup cancelled. You were not logged in." The account still owes what it owed, so its next sign-in is held at enrollment again.

## Your controller

Your `EnrollmentController` extends Keystone's and decides what each outcome looks like. Each outcome leaves through one method:

| Method | Outcome |
|---|---|
| `sendEnrollmentPage(Request $request, EnrollmentPage $page)` | The second-factor step. |
| `sendRecoveryCodesPage(Request $request, RecoveryCodesPage $page)` | The recovery-code step. |
| `sendEnrollmentRefused(Request $request, string $field, string $message)` | A refused answer, with the message for the field. |
| `sendEnrollmentExpired(Request $request, string $field, string $message)` | An answer to a ceremony that ended, with the message for the field. |
| `sendSecondFactorEnrolled(Request $request)` | A stored second factor, on to the recovery codes. |
| `sendSignedIn(Request $request, string $redirectTo)` | A completed sign-in. |
| `sendNoPendingSignIn(Request $request)` | A session that holds no pending sign-in at enrollment. |
| `sendEnrollmentCancelled(Request $request)` | A cancelled sign-in. |

Your `SignInController` and `ChallengeController` each send a sign-in held at enrollment on through `sendHeldForEnrollment(Request $request)`.

## Rate limits

The page counts as a `view`, an answer as a `submit` and cancelling as a `change`, per IP address and per held account. A wrong second-factor answer takes a failed attempt of the type in the `enrollment` flow, apart from the sign-in and challenge flows' counts. A mistyped recovery code takes none. See [rate limits](security.md#rate-limits).

## Production

In production, Keystone refuses to boot with `require_second_factor` on while [`methods`](configuration.md#choosing-the-methods) lists no type on both enrollment and the challenge, since no account could then finish enrolling. Install a type that can be enrolled, such as [TOTP](totp.md), or turn the mandate off.

## Testing

The AppTests check each outcome through an assertion you can redefine in your app's `EnrollmentAssertions` trait: `assertEnrollmentPage()`, `assertRecoveryCodesPage()`, `assertEnrollmentRefused()`, `assertEnrollmentExpired()`, `assertEnrollmentInvalid()`, `assertSecondFactorEnrolled()`, `assertEnrollmentCancelled()`, `assertSessionDemoted()` and `assertSessionDemotedForJson()`, plus `assertHeldForEnrollment()` from `SignInAssertions`. Two more read what a page shows: `shownCeremony()` gives a type's ceremony from the second-factor step, and `shownRecoveryCodes()` the codes from the recovery-code step.

The AppTests keep your app's own config. A test that signs in on a first factor alone, with no second factor or codes, turns both mandates off first:

```php
$this->withoutEnrollmentMandates();

$user = $this->createUser();

$this->assertSignedIn($this->passFirstFactor($user), '/');
```

# Security settings

The security page shows a signed-in user how their account is protected: the credentials they hold, how many recovery codes they have left and whether their session has [sudo](sudo.md). The Inertia-Vue adapter installs it at `GET /settings/security`, named `security`, with the published `app/Http/Controllers/Auth/SecurityController.php` and `resources/js/pages/settings/Security.vue`.

The page needs no sudo. Looking at your own settings changes nothing, so asking the user to prove who they are again would only get in the way. A guest is redirected to sign in. The route counts against the `view` [request limit](rate-limiting.md), 60 a minute by default, and its responses carry the [hardening headers](hardening.md#headers).

## What it shows

Each credential type that `keystone.methods` lists on any surface gets its own section, in the order the method packages registered them, even when the account holds none of that type. A type listed on `enrollment` has a Set up link to [its enrollment step](#adding-a-credential). Each section lists the account's credentials of that type, oldest first, with:

- the name the user gave it, or none;
- when it was added;
- when it last passed a sign-in, the [challenge](challenge.md) or the [sudo replay](sudo.md#the-replay), or never.

A first factor is stamped when it passes, even when the account still owes the challenge. At the sudo replay only the credential that grants sudo is stamped, so a password that passed the replay's first step keeps its earlier time.

The password section says whether the account has a password it can sign in with. A password has no name and the account holds at most one.

Credentials of a type that `keystone.methods` no longer lists, or that no installed package registers, are shown apart as leftovers. They don't count as a way to sign in or as a second factor, and Keystone keeps them stored so they count again if you list the type again.

The page also shows how many unspent [recovery codes](challenge.md#recovery-codes) the account holds. It warns that the account is running low at three or fewer, and says it has none at zero.

When the session has sudo that the [gate](sudo.md#gating-a-route) would accept from the user's current network, the page shows when it ends, with a button that [ends it](sudo.md#ending-it). Otherwise it says the next change will ask the user to confirm who they are.

The page shows only the signed-in account's own credentials and codes. A credential Keystone disabled, such as a security key it caught being cloned, is still listed and marked disabled. It no longer counts as a way to sign in or as a second factor.

## The page value

`SecurityController::sendSecurityPage()` receives a `SecurityPage` and renders it:

| Field | Value |
|---|---|
| `types` | one entry per listed type: `type`, `enrollable` (whether `keystone.methods` lists it on `enrollment`), and `credentials`, each with `id`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `leftovers` | the credentials of types no longer listed, each with `id`, `type`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `recoveryCodes` | how many unspent recovery codes the account holds |
| `recoveryCodesLow` | whether that is three or fewer |
| `sudoEndsAt` | when the session's sudo ends, or `null` |
| `status` | the translated status a previous request flashed, such as the one for `sudo-revoked`, or `null` |
| `offersSignOutOthers` | whether to offer [signing out the other sessions](#signing-out-other-sessions) next to the status, after an enrollment from this page |

Times are ISO 8601 strings in UTC, which the published page formats in the browser's locale.

```php
protected function sendSecurityPage(Request $request, SecurityPage $page): Response
{
    Inertia::encryptHistory();

    return Inertia::render('settings/Security', [
        'types' => $page->types,
        'leftovers' => $page->leftovers,
        'recoveryCodes' => $page->recoveryCodes,
        'recoveryCodesLow' => $page->recoveryCodesLow,
        'sudoEndsAt' => $page->sudoEndsAt,
        'status' => $page->status,
        'offersSignOutOthers' => $page->offersSignOutOthers,
    ]);
}
```

The page names the types it knows, such as "Authenticator app" for `totp`, and shows any other type by its name. Add your own names to `typeNames` in `resources/js/lib/credentialTypes.ts`, which the security page and the confirm step share.

## Adding a credential

The Set up link opens the type's enrollment step at `GET /settings/security/enroll/{type}`, named `security.enroll`. The step starts the type's ceremony, such as [TOTP](totp.md#enrolling) making a new key, and shows its form. Reloading shows the same ceremony. The user answers with `POST /settings/security/enroll/{type}`, named `security.enroll.submit`, and cancels with `DELETE /settings/security/enroll/{type}`, named `security.enroll.cancel`.

The step and the answer need [sudo](sudo.md#gating-a-route). A session without it is sent to confirm who the user is, and then back to the type's step. Cancelling needs no sudo, because it only forgets the ceremony. The step counts against the `start` [request limit](rate-limiting.md), the answer against the `submit` limit and cancelling against the `change` limit, each 10 a minute by default. A type that `keystone.methods` doesn't list on `enrollment` has no step: the user is sent back to the security page.

A ceremony lasts 15 minutes at most, and never longer than the sudo it was started under. The ceremony also ends with its sudo: when the sudo runs out, when the user ends it, or when the user's network changes. A later sudo never finishes an enrollment that an earlier one started. An answer that arrives with no ceremony running sends the user back to the type's step with the `enrollment-expired` status, where a new ceremony starts.

A correct answer stores the credential and records `credential.added` with flow `settings` in one change, which [alerts](security-alerts.md) the account's owner. The user's session gets a new session id and keeps its sudo, and the user is sent to the security page with the `enrolled` status, which [offers to sign out the other sessions](#after-an-enrollment). Adding a credential signs out no other session. The exception is a type whose new credential replaces the one the account holds, as a [TOTP key](totp.md#enrolling) does: the old credential is deleted in the same change, and every other session of the account is signed out.

A wrong answer stores nothing and keeps the ceremony. It is refused with "The provided credential is invalid." on the type's field, and nothing typed is flashed back. It records `proof.rejected` with flow `settings` and counts as a failed attempt in the `settings` flow, apart from the counts that sign-in, the challenge and the sudo replay keep.

Keystone checks three things again as it writes, while it holds the account's row lock, because time passes while an answer is verified:

- An account suspended in the meantime gets nothing stored, recording `proof.rejected` with the reason `keystone.barred`.
- A type taken off `enrollment` in the meantime gets nothing stored, recording the reason `keystone.unoffered`.
- A session whose sudo ended in the meantime gets nothing stored. The user is asked to confirm who they are again and lands back on the type's step. This doesn't count as a wrong answer.

`CredentialEnrollmentController::sendCredentialEnrollmentForm()` receives the same `EnrollmentFormPage` as the form a [held sign-in](enrollment.md#choosing-a-second-factor) enrolls with, and renders it:

| Field | Value |
|---|---|
| `type` | the credential type |
| `shape` | the shape of its form, such as `form` |
| `ceremony` | what the type's ceremony shows, such as a new key; empty for a type with no ceremony |
| `status` | the translated `enrollment-expired` status when an earlier ceremony ended, or `null` |

```php
protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): Response
{
    Inertia::encryptHistory();

    return Inertia::render('settings/CredentialEnrollment', [
        'type' => $page->type,
        'shape' => $page->shape,
        'ceremony' => $page->ceremony,
        'status' => $page->status,
    ]);
}
```

The other outcomes each have a hook in the published `app/Http/Controllers/Auth/CredentialEnrollmentController.php`:

| Hook | Outcome | The published controller |
|---|---|---|
| `sendCredentialEnrollmentNotStarted()` | the type's ceremony couldn't start | sends the user to the security page with the message on the type |
| `sendCredentialEnrollmentRefused()` | a wrong answer | sends the user back to the type's step with the message on the type's field |
| `sendCredentialEnrollmentExpired()` | an answer with no ceremony running | sends the user back to the type's step, which shows the flashed status |
| `sendCredentialEnrolled()` | the credential was stored | sends the user to the security page, which shows the flashed status |
| `sendCredentialEnrollmentCancelled()` | the user cancelled | sends the user to the security page |

The published page, `resources/js/pages/settings/CredentialEnrollment.vue`, shows the same credential type form as the sign-in pages. It passes the form the `settings` purpose, so the form posts to `security.enroll.submit`.

## Removing a credential

Every credential on the page has a Remove link, leftovers and disabled credentials included. It opens a confirm step at `GET /settings/security/credentials/{credential}/remove`, named `security.credentials.remove`, which names the credential by the name the user gave it, or by its type when it has none. The user confirms with `DELETE /settings/security/credentials/{credential}`, named `security.credentials.remove.submit`.

Both routes need [sudo](sudo.md#gating-a-route). A session without it is sent to confirm who the user is, and then back to the confirm step. The confirm step counts against the `view` [request limit](rate-limiting.md) and the removal against the `change` limit, 10 a minute by default.

Removing a credential deletes it and signs out every other session of the account, so a browser that held the removed credential is signed out too. The user's own session stays signed in, gets a new session id and keeps its sudo. Keystone records `credential.removed` with the credential's type, id and name, which [alerts](security-alerts.md) the account's owner, and sends the user to the security page with the `credential-removed` status.

Two checks keep the user from locking themselves out. On PostgreSQL and MySQL they run while Keystone holds the account's row lock and read the credentials with a locking read, so of two removals at once the second waits for the first and sees what it removed. SQLite has no row locks, so there the second of two removals at once fails with a database error and removes nothing.

- The account keeps its last way to sign in, which is the only credential it holds of a type listed on sign-in that isn't disabled. The user sees "You cannot remove your only way to sign in."
- While `keystone.require_second_factor` is on, the account keeps its last second factor, which is the only credential it holds of a type listed on the challenge that isn't disabled. The user sees "You cannot remove your last two-factor credential while two-factor authentication is required." This counts what the [challenge](challenge.md) and [enrollment](enrollment.md) count, so the check never keeps a credential the challenge wouldn't ask for.

A leftover or a disabled credential counts as neither, so removing one is never refused. A refused removal deletes nothing and sends the user back to the confirm step with the message, through `sendRemovalRefused()`.

An id the account doesn't hold, such as another account's credential, one already removed or one that isn't a number, removes nothing. The user is sent to the security page with the `credential-not-found` status, from the confirm step and the removal alike.

`CredentialRemovalController::sendRemovalPage()` receives a `CredentialRemovalPage` and renders it:

| Field | Value |
|---|---|
| `id` | the credential's id |
| `type` | its type |
| `label` | the name the user gave it, or `null` |
| `listed` | whether `keystone.methods` still lists its type; `false` for a leftover |

```php
protected function sendRemovalPage(Request $request, CredentialRemovalPage $page): Response
{
    Inertia::encryptHistory();

    return Inertia::render('settings/CredentialRemoval', [
        'id' => $page->id,
        'type' => $page->type,
        'label' => $page->label,
        'listed' => $page->listed,
    ]);
}
```

`sendCredentialRemoved()` and `sendCredentialNotFound()` answer the other two outcomes. The published controller sends both to the security page, which shows the flashed status.

## Signing out other sessions

The page links to a confirm step at `GET /settings/security/sessions/others/revoke`, named `security.sessions.others.revoke`. The user confirms with `DELETE /settings/security/sessions/others`, named `security.sessions.others.revoke.submit`.

Both routes need [sudo](sudo.md#gating-a-route). A session without it is sent to confirm who the user is, and then back to the confirm step. The confirm step counts against the `view` [request limit](rate-limiting.md) and the sign-out against the `change` limit, 10 a minute by default.

Signing out the other sessions moves the account's credential epoch, so every other browser signed in to the account is signed out on its next request, and a remember-me cookie from before no longer signs it back in. This works on every session driver. On the `database` driver, Keystone also deletes the other sessions' rows from `session.table`. The user's own session stays signed in, gets a new session id and keeps its sudo. Keystone records `sessions.revoked_others`, which [alerts](security-alerts.md) the account's owner, and sends the user to the security page with the `other-sessions-revoked` status.

The confirm step shows nothing about the account, so `sendSignOutOthersPage()` receives only the request:

```php
protected function sendSignOutOthersPage(Request $request): Response
{
    return Inertia::render('settings/SignOutOthers');
}
```

`sendOtherSessionsRevoked()` answers the sign-out. The published controller sends the user to the security page, which shows the flashed status.

### After an enrollment

A credential added from this page is a good moment to sign out a browser the user doesn't recognise. After an [enrollment](#adding-a-credential), `offersSignOutOthers` is `true` and the published page shows a "Sign out your other sessions" button next to the `enrolled` status. The button sends `DELETE /settings/security/sessions/others` straight away, without the confirm step, because clicking it is the confirmation. The enrollment kept the session's sudo, so the sign-out passes the gate.

On the `database` driver, Keystone first checks `session.table` and offers the sign-out only when the account has another session that has made a request since its credential epoch last moved. On other drivers Keystone can't tell, so it always offers it.

## Testing

Keystone's AppTests sign in, give the account's credential a name, add another account's credential and open the page. They check your response through `Tests\Keystone\Assertions\SecurityAssertions`: `assertSecurityPage()` receives the names the page must list in order, and `assertSecurityPageOmits()` a name it must not list. A guest's redirect goes through `assertGuestSentAwayFromSecurity()`. If you change what `sendSecurityPage()` returns, redefine the assertion there.

The enrollment AppTests enroll a credential of the first type an account can enroll as a second factor. They also answer wrongly, answer with no ceremony running, cancel, try without sudo and try a type whose ceremony can't start. They check your responses through `Tests\Keystone\Assertions\CredentialEnrollmentAssertions`, which has an assertion named after each hook, and `assertCredentialEnrollmentRestarted()` for the form that shows the `enrollment-expired` status.

The removal AppTests remove a credential, try another account's and try without sudo. They also try to remove the account's only way to sign in, and its last second factor while one is required. They check your responses through `Tests\Keystone\Assertions\CredentialRemovalAssertions`: `assertRemovalPage()` receives the name the confirm step must show, `assertRemovalRefused()` the credential's id and the message, and `assertCredentialRemoved()` and `assertCredentialNotFound()` check the other outcomes.

The sign-out AppTests sign in from a second browser, sign out the other sessions and check that the second browser is signed out. They also run on the `database` session driver and check that only the user's own row is left, and try without sudo. They check your responses through `Tests\Keystone\Assertions\OtherSessionsAssertions`: `assertSignOutOthersPage()` and `assertOtherSessionsRevoked()`, one for each hook.

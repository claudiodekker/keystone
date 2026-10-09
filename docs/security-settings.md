# Security settings

The security page shows a signed-in user how their account is protected: the credentials they hold, how many recovery codes they have left and whether their session has [sudo](sudo.md). The Inertia-Vue adapter installs it at `GET /settings/security`, named `security`, with the published `app/Http/Controllers/Auth/SecurityController.php` and `resources/js/pages/settings/Security.vue`.

The page needs no sudo. Looking at your own settings changes nothing, so asking the user to prove who they are again would only get in the way. A guest is redirected to sign in. The route counts against the `view` [request limit](rate-limiting.md), 60 a minute by default, and its responses carry the [hardening headers](hardening.md#headers).

Password managers open the page through `GET /.well-known/change-password`, named `well-known.change-password`, which the published routes file redirects to `security`. It needs no sign-in: the page's own check sends a guest to sign in first.

```php
Route::get('.well-known/change-password', fn () => to_route('security'))->name('well-known.change-password');
```

## What it shows

Each credential type that `keystone.methods` lists on any surface gets its own section, in the order the method packages registered them, even when the account holds none of that type. A type listed on `enrollment` has a Set up link to [its enrollment step](#adding-a-credential), unless the type refuses to be set up, as a password does while `keystone.methods` doesn't list passwords on `sign-in`. Each section lists the account's credentials of that type, oldest first, with:

- the name the user gave it, or none;
- when it was added;
- when it last passed a sign-in, the [challenge](challenge.md) or the [sudo replay](sudo.md#the-replay), or never.

A first factor is stamped when it passes, even when the account still owes the challenge. At the sudo replay only the credential that grants sudo is stamped, so a password that passed the replay's first step keeps its earlier time.

The password section says whether the account has a password it can sign in with, and its link reads Change once one is set. A password has no name and the account holds at most one.

Credentials of a type that `keystone.methods` no longer lists, or that no installed package registers, are shown apart as leftovers. They don't count as a way to sign in or as a second factor, and Keystone keeps them stored so they count again if you list the type again.

The page also shows how many unspent [recovery codes](challenge.md#recovery-codes) the account holds. It warns that the account is running low at three or fewer, and says it has none at zero.

When the session has sudo that the [gate](sudo.md#gating-a-route) would accept from the user's current network, the page shows when it ends, with a button that [ends it](sudo.md#ending-it). Otherwise it says the next change will ask the user to confirm who they are.

On the `database` session driver, the page lists the account's [sessions](#sessions). On other drivers it says they can't be listed.

The page shows only the signed-in account's own credentials, codes and sessions. A credential Keystone disabled, such as a security key it caught being cloned, is still listed and marked disabled. It no longer counts as a way to sign in or as a second factor.

## The page value

`SecurityController::sendSecurityPage()` receives a `SecurityPage` and renders it:

| Field | Value |
|---|---|
| `types` | one entry per listed type: `type`, `enrollable` (whether `keystone.methods` lists it on `enrollment` and the type doesn't refuse to be set up), and `credentials`, each with `id`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `leftovers` | the credentials of types no longer listed, each with `id`, `type`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `recoveryCodes` | how many unspent recovery codes the account holds |
| `recoveryCodesLow` | whether that is three or fewer |
| `sudoEndsAt` | when the session's sudo ends, or `null` |
| `status` | the translated status a previous request flashed, such as the one for `sudo-revoked`, or `null` |
| `sessions` | the account's [live sessions](#sessions), this device first, each a `SessionRow`; empty on a driver that can't list them |
| `sessionsStatus` | the translated `sessions-unavailable` status on a driver that can't list sessions, or `null` |
| `offersSignOutOthers` | whether to offer [signing out the other sessions](#signing-out-other-sessions) next to the status, after an enrollment from this page added a credential |

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
        'sessions' => $page->sessions,
        'sessionsStatus' => $page->sessionsStatus,
        'offersSignOutOthers' => $page->offersSignOutOthers,
    ]);
}
```

The page names the types it knows, such as "Authenticator app" for `totp`, and shows any other type by its name. Add your own names to `typeNames` in `resources/js/lib/credentialTypes.ts`, which the security page and the confirm step share.

## Adding a credential

The Set up link opens the type's enrollment step at `GET /settings/security/enroll/{type}`, named `security.enroll`. The step starts the type's ceremony, such as [TOTP](totp.md#enrolling) making a new key, and shows its form. Reloading shows the same ceremony. The user answers with `POST /settings/security/enroll/{type}`, named `security.enroll.submit`, and cancels with `DELETE /settings/security/enroll/{type}`, named `security.enroll.cancel`.

The step and the answer need [sudo](sudo.md#gating-a-route). A session without it is sent to confirm who the user is, and then back to the type's step. Cancelling needs no sudo, because it only forgets the ceremony. The step counts against the `start` [request limit](rate-limiting.md), the answer against the `submit` limit and cancelling against the `change` limit, each 10 a minute by default. A type that `keystone.methods` doesn't list on `enrollment` has no step: the user is sent back to the security page. A type can also refuse to be set up, with a reason worded for the user. A [password](#passwords) refuses while `keystone.methods` doesn't list passwords on `sign-in`. The step and the answer then send the user to the security page with that reason on the type, through `sendCredentialEnrollmentNotStarted()`.

A ceremony lasts 15 minutes at most, and never longer than the sudo it was started under. The ceremony also ends with its sudo: when the sudo runs out, when the user ends it, or when the user's network changes. A later sudo never finishes an enrollment that an earlier one started. An answer that arrives with no ceremony running sends the user back to the type's step with the `enrollment-expired` status, where a new ceremony starts.

A correct answer stores the credential and records `credential.added` with flow `settings` in one change, which [alerts](security-alerts.md) the account's owner. The user's session gets a new session id and keeps its sudo, and the user is sent to the security page with the `enrolled` status, which [offers to sign out the other sessions](#after-an-enrollment). Adding a credential signs out no other session.

Some types keep one credential per account, as a [TOTP key](totp.md#enrolling) and a [password](#passwords) do. When the account already holds a usable one, the new credential replaces it in the same change: the old one is deleted, every other session of the account is signed out, and Keystone records `credential.replaced` with flow `settings` in place of `credential.added`. It alerts the account's owner, and no `credential.removed` is recorded. The user lands on the security page with the `credential-replaced` status. The other sessions are already signed out, so the page doesn't offer to sign them out. A disabled credential of the type is deleted too, but it was no way in, so setting one up over it counts as an addition: `credential.added`, the `enrolled` status, and no other session signed out.

A wrong answer stores nothing and keeps the ceremony. It is refused with "The provided credential is invalid." on the type's field, and nothing typed is flashed back. It records `proof.rejected` with flow `settings` and counts as a failed attempt in the `settings` flow, apart from the counts that sign-in, the challenge and the sudo replay keep.

Keystone checks three things again as it writes, while it holds the account's row lock, because time passes while an answer is verified:

- An account suspended in the meantime gets nothing stored, recording `proof.rejected` with the reason `keystone.barred`.
- A type taken off `enrollment` in the meantime gets nothing stored, recording the reason `keystone.unoffered`.
- A session whose sudo ended in the meantime gets nothing stored. The user is asked to confirm who they are again and lands back on the type's step. This doesn't count as a wrong answer.
- A credential that replaces what the account holds gets nothing stored when the account's credentials of the type changed in the meantime, such as a second password change sent at the same time, recording the reason `keystone.superseded`. The answer was checked against credentials the account no longer holds. The same answer arriving twice, as a double click sends a TOTP code, stores the credential once and refuses nothing.

`CredentialEnrollmentController::sendCredentialEnrollmentForm()` receives the same `EnrollmentFormPage` as the form a [held sign-in](enrollment.md#choosing-a-second-factor) enrolls with, and renders it:

| Field | Value |
|---|---|
| `type` | the credential type |
| `shape` | the shape of its form, such as `form` |
| `ceremony` | what the type's ceremony shows, such as a new key; empty for a type with no ceremony |
| `status` | the translated `enrollment-expired` status when an earlier ceremony ended, or `null` |
| `held` | the account's usable credentials of the type, each with `id`, `label` and `removable`, whether removing it would be allowed; always empty for a held sign-in |

```php
protected function sendCredentialEnrollmentForm(Request $request, EnrollmentFormPage $page): Response
{
    Inertia::encryptHistory();

    return Inertia::render('settings/CredentialEnrollment', [
        'type' => $page->type,
        'shape' => $page->shape,
        'ceremony' => $page->ceremony,
        'status' => $page->status,
        'held' => $page->held,
    ]);
}
```

The other outcomes each have a hook in the published `app/Http/Controllers/Auth/CredentialEnrollmentController.php`:

| Hook | Outcome | The published controller |
|---|---|---|
| `sendCredentialEnrollmentNotStarted()` | the type's ceremony couldn't start, or the type refuses to be set up | sends the user to the security page with the message on the type |
| `sendCredentialEnrollmentRefused()` | a wrong answer | sends the user back to the type's step with the message on the type's field, or on `current_password` for a password |
| `sendCredentialEnrollmentExpired()` | an answer with no ceremony running | sends the user back to the type's step, which shows the flashed status |
| `sendCredentialEnrolled()` | the credential was stored | sends the user to the security page, which shows the flashed status |
| `sendCredentialEnrollmentCancelled()` | the user cancelled | sends the user to the security page |

The published page, `resources/js/pages/settings/CredentialEnrollment.vue`, shows the same credential type form as the sign-in pages. It passes the form the `settings` purpose, so the form posts to `security.enroll.submit`.

### Passwords

A password is added and changed through the same step, at `/settings/security/enroll/password`, and removed like any other credential. The Change or Set up link in the password section opens it.

An account without a password sees "Your account does not have a password set." and two fields: `password` and `password_confirmation`, both with `autocomplete="new-password"`. The new password must pass the [rules for new passwords](password.md#new-passwords). It is stored with `credential.added` and the `enrolled` status, and signs out no other session.

An account with a password also sees `current_password`, with `autocomplete="current-password"`, above the two new-password fields. Keystone checks the current password inside the timing floor, against the password the account holds. A wrong, empty or missing one is a wrong answer: it records `proof.rejected` with the reason `password.mismatch` and counts as a failed attempt in the `settings` flow. A `current_password` that isn't a string is refused as invalid input before anything is checked. The published controller puts the refusal on `current_password`, because the type's field and the new password share the name `password`. A right one stores the new password in place of the old one, records `credential.replaced`, signs out every other session and shows the `credential-replaced` status:

```html
<input name="current_password" type="password" autocomplete="current-password" />
<input name="password" type="password" autocomplete="new-password" />
<input name="password_confirmation" type="password" autocomplete="new-password" />
```

The step's `held` says whether a password is set and whether it can be removed. The published form links to its confirm step with "Remove password" while another way to sign in remains. Removing it shows the `credential-removed` status, and removing the only way to sign in is refused as for any credential. A removal names the credential by its id, so there is no request that removes "the password" of an account that holds none. An id the account doesn't hold gives `credential-not-found`.

While `keystone.methods` doesn't list passwords on `sign-in`, the step and every answer are refused with "Passwords are not supported on this application.", because a password the app can't sign in with protects nothing. A password the account still holds then shows as a leftover, and it can still be removed.

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

## Sessions

On the `database` session driver, the page lists every session signed in to the account. This device comes first, then the others, the most recently active first, up to 50 in all. Each row shows:

- the platform and the browser, read from the user agent of the session's last request;
- the IP address of that request, or "Unknown IP address" when the session stored none;
- where that address is, when the [IP location port](security-alerts.md#ip-location) can tell;
- when the session was last active;
- whether it is this device.

Keystone lists a session only while it is live. It is signed in as the account, it made a request within `session.lifetime`, and the account's credential epoch hasn't moved since. A session that an epoch move ended, such as [signing out other sessions](#signing-out-other-sessions) or [removing a credential](#removing-a-credential), is hidden even while its row is still in the table.

Only the `database` driver keeps a table Keystone can list sessions from. On the `file`, `cookie`, `redis` and other drivers, `sessions` is empty and `sessionsStatus` says the sessions can't be listed. Keystone still boots, and signing out the other sessions still works. [ADR 0025](adr/0025-sessions-are-listed-only-on-the-database-driver.md) records why.

Each `SessionRow` has these fields:

| Field | Value |
|---|---|
| `handle` | the opaque name of the session, which the revoke routes take; never its session id |
| `platform` | the platform, such as "Windows", or `null` |
| `browser` | the browser, such as "Firefox", or `null` |
| `ipAddress` | the IP address of the session's last request, or `null` when it stored none |
| `location` | where that address is, such as "Amsterdam, Netherlands", or `null` |
| `lastActiveAt` | when the session was last active |
| `current` | whether it is the session viewing the page |

The handle is an HMAC of the session id under its own subkey of the app key. It changes whenever the session gets a new id. The session id never reaches the browser, so a page that shows the list can't leak another device's session.

### Revoking one session

Each other session on the list has a Sign out link. It opens a confirm step at `GET /settings/security/sessions/{session}/revoke`, named `security.sessions.revoke`, which shows the session. The user confirms with `DELETE /settings/security/sessions/{session}`, named `security.sessions.revoke.submit`.

Both routes need [sudo](sudo.md#gating-a-route), and count against the same request limits as [signing out other sessions](#signing-out-other-sessions).

Revoking a session deletes its row and the remember-me token it stored, so that browser is signed out on its next request and its remember-me cookie no longer signs it back in. Every other session, this one included, stays signed in, and the credential epoch doesn't move. Keystone records `session.revoked` with the revoked session's IP address and user agent, which [alerts](security-alerts.md) the account's owner with that device's details, and sends the user to the security page with the `session-revoked` status.

The routes refuse three cases:

- This device's own session is refused with "You cannot revoke your current session; sign out instead." through `sendRevocationRefused()`, from the confirm step and the revoke alike. The user ends it by signing out.
- A handle that names none of the account's live sessions, such as one already revoked or another account's, revokes nothing and records nothing. The user is sent to the security page with the `session-not-found` status.
- On a driver that can't list sessions, nothing is revoked. The user is sent to the security page with the `sessions-unavailable` status.

`SessionRevocationController::sendRevocationPage()` receives the session's `SessionRow` and renders it:

```php
protected function sendRevocationPage(Request $request, SessionRow $session): Response
{
    Inertia::encryptHistory();

    return Inertia::render('settings/SessionRevocation', [
        'handle' => $session->handle,
        'platform' => $session->platform,
        'browser' => $session->browser,
        'ipAddress' => $session->ipAddress,
        'location' => $session->location,
        'lastActiveAt' => $session->lastActiveAt,
        'current' => $session->current,
    ]);
}
```

`sendSessionRevoked()`, `sendSessionNotFound()` and `sendSessionsUnavailable()` answer the other outcomes. The published controller sends each to the security page, which shows the flashed status. It sends a refusal there too, with the message on the `session` error, which the page shows in its Sessions section.

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

A credential added from this page is a good moment to sign out a browser the user doesn't recognise. After an [enrollment](#adding-a-credential) that added a credential, `offersSignOutOthers` is `true` and the published page shows a "Sign out your other sessions" button next to the `enrolled` status. An enrollment that replaced a credential already signed the other sessions out, so it offers nothing. The button sends `DELETE /settings/security/sessions/others` straight away, without the confirm step, because clicking it is the confirmation. The enrollment kept the session's sudo, so the sign-out passes the gate.

On the `database` driver, Keystone first checks `session.table` and offers the sign-out only when the account has another session that has made a request since its credential epoch last moved. On other drivers Keystone can't tell, so it always offers it.

## Testing

Keystone's AppTests sign in, give the account's credential a name, add another account's credential and open the page. They check your response through `Tests\Keystone\Assertions\SecurityAssertions`: `assertSecurityPage()` receives the names the page must list in order, and `assertSecurityPageOmits()` a name it must not list. A guest's redirect goes through `assertGuestSentAwayFromSecurity()`. If you change what `sendSecurityPage()` returns, redefine the assertion there.

The enrollment AppTests enroll a credential of the first type an account can enroll as a second factor. They also answer wrongly, answer with no ceremony running, cancel, try without sudo and try a type whose ceremony can't start. They check your responses through `Tests\Keystone\Assertions\CredentialEnrollmentAssertions`, which has an assertion named after each hook, and `assertCredentialEnrollmentRestarted()` for the form that shows the `enrollment-expired` status.

The removal AppTests remove a credential, try another account's and try without sudo. They also try to remove the account's only way to sign in, and its last second factor while one is required. They check your responses through `Tests\Keystone\Assertions\CredentialRemovalAssertions`: `assertRemovalPage()` receives the name the confirm step must show, `assertRemovalRefused()` the credential's id and the message, and `assertCredentialRemoved()` and `assertCredentialNotFound()` check the other outcomes.

The sign-out AppTests sign in from a second browser, sign out the other sessions and check that the second browser is signed out. They also run on the `database` session driver and check that only the user's own row is left, and try without sudo. They check your responses through `Tests\Keystone\Assertions\OtherSessionsAssertions`: `assertSignOutOthersPage()` and `assertOtherSessionsRevoked()`, one for each hook.

The revoke AppTests run on the `database` session driver. They sign in from a second browser, revoke its session and check that it is signed out while this one stays signed in. They also try this device's own session, another account's session and a revoke without sudo, and try a revoke on the `file` driver. They check your responses through `Tests\Keystone\Assertions\SessionRevocationAssertions`, which has an assertion named after each hook: `assertRevocationRefused()` receives the message.

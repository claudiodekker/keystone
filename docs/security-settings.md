# Security settings

The security page shows a signed-in user how their account is protected: the credentials they hold, how many recovery codes they have left and whether their session has [sudo](sudo.md). The Inertia-Vue adapter installs it at `GET /settings/security`, named `security`, with the published `app/Http/Controllers/Auth/SecurityController.php` and `resources/js/pages/settings/Security.vue`.

The page needs no sudo. Looking at your own settings changes nothing, so asking the user to prove who they are again would only get in the way. A guest is redirected to sign in. The route counts against the `view` [request limit](rate-limiting.md), 60 a minute by default, and its responses carry the [hardening headers](hardening.md#headers).

## What it shows

Each credential type that `keystone.methods` lists on any surface gets its own section, in the order the method packages registered them, even when the account holds none of that type. Each section lists the account's credentials of that type, oldest first, with:

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
| `types` | one entry per listed type: `type`, and `credentials`, each with `id`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `leftovers` | the credentials of types no longer listed, each with `id`, `type`, `label`, `addedAt`, `lastUsedAt` and `disabled` |
| `recoveryCodes` | how many unspent recovery codes the account holds |
| `recoveryCodesLow` | whether that is three or fewer |
| `sudoEndsAt` | when the session's sudo ends, or `null` |
| `status` | the translated status a previous request flashed, such as the one for `sudo-revoked`, or `null` |

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
    ]);
}
```

The page names the types it knows, such as "Authenticator app" for `totp`, and shows any other type by its name. Add your own names to `typeNames` in `Security.vue`.

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

## Testing

Keystone's AppTests sign in, give the account's credential a name, add another account's credential and open the page. They check your response through `Tests\Keystone\Assertions\SecurityAssertions`: `assertSecurityPage()` receives the names the page must list in order, and `assertSecurityPageOmits()` a name it must not list. A guest's redirect goes through `assertGuestSentAwayFromSecurity()`. If you change what `sendSecurityPage()` returns, redefine the assertion there.

The removal AppTests remove a credential, try another account's and try without sudo. They also try to remove the account's only way to sign in, and its last second factor while one is required. They check your responses through `Tests\Keystone\Assertions\CredentialRemovalAssertions`: `assertRemovalPage()` receives the name the confirm step must show, `assertRemovalRefused()` the credential's id and the message, and `assertCredentialRemoved()` and `assertCredentialNotFound()` check the other outcomes.

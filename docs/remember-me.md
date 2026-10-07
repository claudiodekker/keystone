# Remember me

A user who ticks "Remember me" when they sign in comes back signed in on that browser after their session is gone, for 30 days from that sign-in. The Keystone guard does this itself whenever something asks who is signed in, so there is no middleware to add and no route to change.

## The checkbox

The sign-in page value has a `rememberOffered` field, which is `true` while remember-me is on. The published Inertia-Vue sign-in form reads it and shows the checkbox. In a form of your own, post a field named `remember` with the first factor:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { submit } from '@/routes/login';

defineProps<{ rememberOffered: boolean }>();
</script>

<template>
    <Form v-bind="submit.form({ type: 'password' })">
        <input name="identifier" type="email" autocomplete="username" required />
        <input name="password" type="password" autocomplete="current-password" required />

        <label v-if="rememberOffered">
            <input name="remember" type="checkbox" value="1" />
            Remember me
        </label>

        <button type="submit">Sign in</button>
    </Form>
</template>
```

Keystone reads `remember` as a boolean, so `1`, `true`, `on` and `yes` are a tick and anything else is not. The field never reaches the credential type and never fails validation.

A ticked sign-in is remembered only once it completes. When the account owes the [challenge](challenge.md) or an [enrollment](enrollment.md) first, Keystone keeps the tick with the pending sign-in and issues the cookie when the last step signs the user in. A field named `remember` posted to a later step is ignored.

## The cookie

A remembered sign-in hands the browser a `__Host-keystone_remember` cookie: 256 random bits as 64 hexadecimal characters, host-only, `Secure`, `HttpOnly` and `SameSite=Lax`. It holds no user id. Laravel doesn't encrypt it, because Keystone adds it to the `EncryptCookies` middleware's exceptions itself.

Keystone stores only the SHA-256 digest of the value, in `user_remember_tokens`, with the account, the account's credential epoch at the sign-in, and the time the token ends. Each browser has its own token, so an account remembered on a laptop and a phone has two.

## The lifetime

`keystone.remember.lifetime_seconds` sets how long a token lasts, counted from the sign-in that issued it. The default is 30 days. To remember a browser for a week:

```php
'remember' => [
    'lifetime_seconds' => 604800,
],
```

The end time is written on the token when it is issued, and the cookie expires at that same moment. Coming back never moves it, so a user who returns every day still signs in again after the lifetime. Changing the setting affects only tokens issued afterwards.

Set it to `0` to turn remember-me off. `rememberOffered` is then `false`, a posted `remember` is ignored, and a cookie issued earlier is not read. Tokens issued earlier stay in the table until they end, so turning the setting back on within their lifetime lets them restore again. To end them at once, end the sessions they belong to with [`keystone:end-sessions`](operator-commands.md#ending-sessions).

## Coming back

When a request has no signed-in session and carries a live cookie, Keystone signs the browser in as the token's account:

- the session gets a new id, the account's credential epoch and a new sign-in time, so its [absolute lifetime](configuration.md#session-lifetime) starts again;
- `signed_in` is recorded with the reason `remembered` and no credential (see [Security events](security-events.md));
- Laravel's `Login` event fires with `remember` set to `true`, and `Auth::viaRemember()` answers `true` for that request.

The same happens when a signed-in session outlives its absolute lifetime while its cookie is still live. The session carries on under a new id with its data kept, and the user sees no notice: there is no `session.ended` event, no `Clear-Site-Data` header and no redirect to sign in.

A return changes neither the token nor the cookie, so a browser that reopens with several tabs restores in each of them. It also leaves the [device cookie](security-alerts.md#new-devices) as it was. A return from a browser that isn't one of the account's known devices, such as one that a copied cookie was pasted into, alerts the account's owner like any sign-in from a new device.

A remembered return is a full sign-in. The second factor is not asked again, which is why the lifetime is fixed and why `0` exists. To ask for a fresh proof before a sensitive change in your own app, check `Auth::viaRemember()`.

Keystone restores only after Laravel's `StartSession` middleware has run. A global middleware of yours that asks who is signed in before it sees a guest.

## What ends it

| What happens | Effect |
|---|---|
| The user signs out on that browser | That browser's token and cookie are deleted. The account's other browsers stay remembered. |
| The token reaches its end time | It no longer restores. |
| The account's other sessions are ended, such as by a password change or `keystone:end-sessions` | Every token of the account stops restoring, because it was issued on an older credential epoch. |
| The account is suspended, invalidated or deleted | Its tokens stop restoring. |
| The account newly owes [enrollment](enrollment.md#signed-in-sessions-that-newly-owe) | A return is refused and the user signs in again, as below. |

One browser survives the third row. When a signed-in user makes the change that ends their other sessions, their own session stays signed in, and their browser is handed a new cookie value for the same token. The token keeps its end time, and the old value stops working with the other sessions.

A cookie that no longer restores is dropped from the browser the first time it is presented, and its token is deleted. Keystone records `request.rejected` with the reason `keystone.dead_remember_cookie`, on the account's audit trail when the cookie named a token and in the log alone when it didn't. The user is a guest and sees no message.

When the account newly owes enrollment, for example because you turned a mandate on, the return is refused the same way with the reason `keystone.enrollment_owed`, and the user is told why: a browser is redirected to sign in with the status "Please sign in again to finish setting up two-factor authentication.", and a request expecting JSON gets a `401` with the reason `demoted`. Signing in again proves the account afresh before it enrolls. A cookie never enrolls anything, so a stolen one can't add its own second factor. A signed-in session that is ended for the same reason forgets its token too.

If you list `cookies` in [`keystone.clear_site_data`](hardening.md#clearing-site-data), the browser also drops the remember-me cookie whenever Keystone ends a session.

## Testing

Keystone's AppTests sign in with the box ticked, drop the session and check the browser comes back signed in, and that signing out ends that. They are skipped while `remember.lifetime_seconds` is `0`. In tests of your own that extend `AppTestCase`, `rememberCookieOf($response)` reads the value a response handed out and `fromRememberCookie($value)` sends it on later requests. Here `/dashboard` is a page behind your `auth` middleware:

```php
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;

pest()->extend(AppTestCase::class);

it('brings a remembered user back signed in', function () {
    $this->withoutMandates();
    $support = $this->supportsFor(Surface::SIGN_IN)[0];
    $account = $this->createFirstFactorAccount();
    $response = $this->submitSignIn($support, 'jane@example.com', [...$support->validProof(Surface::SIGN_IN), 'remember' => '1']);
    session()->invalidate();

    $page = $this->fromRememberCookie($this->rememberCookieOf($response))->get('/dashboard');

    $page->assertOk();
    $this->assertAuthenticatedAs($account);
});
```

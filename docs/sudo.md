# Sudo

Sudo is a short grant on a signed-in session. It says the person at the keyboard proved who they are a moment ago, which is what a change to how the account signs in should rest on. A session has sudo for 15 minutes after its sign-in. When it needs sudo and has none, Keystone asks the user to prove it's them again, the way they sign in, and then grants it for another 15 minutes. The grant is bound to the subnet it was earned from, and the user can end it sooner.

Keystone keeps the grant in the session, next to the sign-in itself, so it needs no table. You gate a route with one middleware.

## Gating a route

Put `sudo` after `auth` on any route that changes how the account signs in or reaches it:

```php
use App\Http\Controllers\Settings\ApiTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'sudo'])->group(function () {
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('settings.api-tokens');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('settings.api-tokens.destroy');
});
```

Keystone registers the `sudo` alias. On every request to the route it checks the session before your controller runs:

| The request | What happens |
|---|---|
| comes from a signed-in session with a live grant, from the grant's subnet | it reaches your controller |
| comes from a signed-in session with a live grant, from another subnet | the grant is revoked, `sudo.network_changed` is recorded and the owner alerted, then as below |
| comes from a signed-in session without a live grant, from a browser | the session gets a [sudo-in-progress](#the-replay) and the browser is redirected to the sudo page, `GET /auth/sudo` |
| comes from a signed-in session without a live grant, from a JSON client | the session gets a sudo-in-progress and the client gets `403` with `{"message": "...", "reason": "sudo"}` |
| comes from a guest | the `auth` middleware answers as it always does |

The redirect and the 403 carry the [hardening headers](hardening.md#headers), even though your route isn't a Keystone route. The gate itself records nothing: a session being asked for sudo is not an event. Once sudo is granted, the user lands on the page they asked for. A request that would have changed something is never replayed; the user lands on the page it came from instead, or on `/` when that page isn't one of yours.

A core controller that needs sudo checks it twice: in its middleware list and on the first line of the action. Replacing either in your published copy still leaves the action refused without sudo. The confirm step and the removal of [removing a credential](security-settings.md#removing-a-credential) are such actions, and so are the enrollment step and its answer when [adding one](security-settings.md#adding-a-credential), and the confirm step and the sign-out of [signing out other sessions](security-settings.md#signing-out-other-sessions).

Whatever a credential type starts while the session has sudo, such as the new key of a TOTP enrollment, ends when that sudo ends. An enrollment started under one grant is never finished under the next.

To answer a refused request differently, such as with a modal rather than a redirect, bind your own `RespondToSudoRequired` in a service provider. The decision stays Keystone's; only the response changes:

```php
use ClaudioDekker\Keystone\Actions\RespondToSudoRequired;

$this->app->bind(RespondToSudoRequired::class, RespondWithSudoModal::class);
```

Your class extends `RespondToSudoRequired` and overrides `handle(Request $request): Response`.

## When a session has it

Every sign-in that proves the account brings sudo: a first factor that signs in on its own, a passed [challenge](challenge.md), and the last step of an [enrollment](enrollment.md). Keystone records `sudo.granted` with the reason `keystone.sign_in` each time (see [Security events](security-events.md)).

A sign-in restored by a [remember-me cookie](remember-me.md#coming-back) proves nothing, so it brings no sudo and records no `sudo.granted`. That also holds when the cookie restores a session that outlived its [absolute lifetime](configuration.md#session-lifetime): the restored session starts without sudo, even when the sign-in before it was minutes old. Such a session earns sudo through the replay like any other.

A session holds at most one grant. Whenever a session is held, signed in, restored or signed out, its id is rotated and its grant is dropped with it, so a grant never outlives the sign-in that brought it. One change keeps the grant: when a user ends their other sessions, their own session stays signed in and keeps its sudo.

## The replay

A session the gate refused holds a sudo-in-progress. It owes the same steps a sign-in of its account would take, and it remembers where to go afterwards. The sudo page at `GET /auth/sudo` shows the step the session is at.

The first step offers the sign-in credential types the account holds, such as its password. The page preselects the first and lets the user switch to another. When the account holds a second factor, passing the first step moves the session to the second: the same page now offers the account's challenge types, leaving out the type that passed the first step, and then [recovery codes](challenge.md#recovery-codes) while the account holds any. An account whose first factor proves two factors on its own, such as a passkey, is done after one step, as it is at sign-in. An account without a second factor is done after one step too. The offer comes from the same decision that drives sign-in, so an account holding a second factor never clears sudo on its first factor alone.

A correct answer to the last step grants sudo: the session id is rotated, the grant is bound to the subnet the answer came from, the credential's `last_used_at` is stamped, and `sudo.granted` is recorded with flow `sudo` and the credential that earned it. The user is then sent to the page the gate refused. A recovery code can answer the second step. It is spent, and `recovery_code.used` is recorded with flow `sudo`. The last code is always kept, whatever `require_recovery_codes` says.

A wrong answer is refused with "The provided credential is invalid." on the type's field, records `sudo.failed` with flow `sudo`, and mails the owner a `sudo.failed` [alert](security-alerts.md), even when the browser is one of the account's known devices: someone with the session, or a copy of it, is guessing. It counts one failed attempt in the `sudo` flow for that type, apart from the counts sign-in and the challenge keep, except that wrong [TOTP](totp.md) codes share the challenge's count (see [Rate limiting](rate-limiting.md)). Once the sudo flow's count is spent, every answer gets a `429` until the count expires.

Nothing is verified unless the gate asked for it. An answer from a session without a sudo-in-progress, or for a type the step doesn't offer, is refused without checking the credential or counting anything. An answer from an address that isn't an IP address is counted and refused like a wrong answer, with the reason `keystone.unbindable_subnet`, because a grant is never made without a subnet.

A sudo-in-progress lasts 15 minutes from the refusal that started it. Being refused again inside those minutes buys no more time. A browser refused on another page goes to that page afterwards; a refused mutation or JSON request changes nothing. When it runs out, the sudo page sends the user to `/`, and their next request to a gated route starts a fresh one. Whatever a method was in the middle of on the sudo page, such as a challenge a security key must sign, ends with it.

Each step of the replay is rate limited as the matching sign-in step is: the page counts as a view and an answer as a submission (see [Rate limiting](rate-limiting.md)).

## The page

The published `SudoController` renders `resources/js/pages/auth/Sudo.vue` with the step's types, the preselected type and the surface the step verifies on, `sign-in` or `challenge`. The page reuses the credential type forms of the sign-in and challenge pages, with the identifier and remember-me fields left out and the button reading "Confirm". Each outcome has its response hook in `app/Http/Controllers/Auth/SudoController.php`: `sendSudoPage()` renders the page, `sendSudoRefused()` sends a wrong answer back to it with the message on the type's field, `sendSudoChallengeOwed()` sends a passed first step on to the second, and `sendSudoGranted()` sends the user to the intended URL. Change any of them there.

A guest is redirected to sign in. A signed-in session nothing was demanded of is sent to `/`, because without a refusal there is no page to go back to.

## The lifetime

`keystone.sudo.lifetime_seconds` sets how long a grant lasts, counted from the sign-in or the replay that brought it. The default is 15 minutes. To keep it for 5 minutes:

```php
'sudo' => [
    'lifetime_seconds' => 300,
],
```

Nothing extends a grant. A busy session loses sudo at the same second as an idle one. The setting is read when the grant is checked, so changing it applies to the grants sessions already hold. It must be a whole number of at least 1, or your app doesn't boot (see [Boot checks](configuration.md#boot-checks)). The 15 minutes a sudo-in-progress lasts aren't configurable.

## The subnet

A grant is bound to the subnet it was earned from, so the same session cookie used on another network can't make the change. The subnet is the first 24 bits of an IPv4 address and the first 64 bits of an IPv6 address:

| The grant was earned from | It is bound to |
|---|---|
| `203.0.113.77` | `203.0.113.0/24` |
| `::ffff:203.0.113.77` (IPv4 written as IPv6) | `203.0.113.0/24` |
| `2001:db8:0:1:aaaa:bbbb:cccc:dddd` | `2001:db8:0:1::/64` |

When a live grant is used from another subnet, the gate revokes it at once, rotates the session id, records `sudo.network_changed` and mails the owner (see [Security alerts](security-alerts.md)), then asks for the replay as if the session had no grant. The session stays signed in. A grant that already ran out is dropped without a record, whatever subnet the request comes from. A request from an address that isn't an IP address can't be shown to come from the bound subnet, so it revokes a live grant too.

Keystone reads the request's address once, when its global middleware runs, after any trusted-proxy middleware in the global middleware stack. Behind a load balancer, configure Laravel's trusted proxies there, or every grant is bound to the balancer's subnet and the check stops telling networks apart.

When a sign-in has no address, such as one made from a command or a queued job, or its address isn't an IP address, the sign-in still completes, but it brings no sudo and records no `sudo.granted`. A grant is never made without a subnet.

Only the grant is bound. The session itself follows the user from one network to another, as it did before.

## Ending it

A user who is done with their changes can end sudo before it runs out, for example on a computer they share. The published `SudoController` does it on the `sudo.end` route, `DELETE /auth/sudo`. The response sends the user to the [security page](security-settings.md), which needs no sudo and already shows this button while the session has sudo. A button on another page of yours:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { end } from '@/routes/sudo';
</script>

<template>
    <Form v-bind="end.form()">
        <button type="submit">End sudo</button>
    </Form>
</template>
```

Keystone then drops the grant, or the sudo-in-progress, rotates the session id, records `sudo.revoked` when a live grant was ended, and flashes the status `sudo-revoked`. The user stays signed in. `sendSudoEnded()` in your `app/Http/Controllers/Auth/SudoController.php` sends them to the security page, which shows the status's translated message:

```php
protected function sendSudoEnded(Request $request): RedirectResponse
{
    return to_route('security');
}
```

To send them somewhere else, such as back to the page they came from, return that redirect instead. The page they land on reads the flashed status and passes its translated message along, as the security page does:

```php
use ClaudioDekker\Keystone\Status;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController
{
    public function show(Request $request): Response
    {
        return Inertia::render('Home', [
            'status' => Status::flashed($request)?->label(),
        ]);
    }
}
```

The message is `keystone::messages.status.sudo-revoked`, and you can translate it in `lang/vendor/keystone`.

Ending sudo is safe to repeat. A request from a session whose grant already ended or ran out answers the same way and rotates the session id again, but records no `sudo.revoked`, because nothing was revoked. A guest is redirected to sign in. The route counts against the `change` [request limit](rate-limiting.md), 10 a minute by default, and its responses carry the [hardening headers](hardening.md#headers).

## Testing

Keystone's AppTests register a route behind `['auth', 'sudo']`, sign in, end sudo, meet the gate, replay the sign-in and end sudo again. They check your responses through `Tests\Keystone\Assertions\SudoAssertions`. If you change what a hook returns, redefine its assertion there. For a `sendSudoEnded()` that returns `back(fallback: '/')`, which lands on `/` because the AppTests end sudo from no page:

```php
namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SudoAssertions as InertiaVueSudoAssertions;
use Illuminate\Testing\TestResponse;

trait SudoAssertions
{
    use InertiaVueSudoAssertions;

    public function assertSudoEnded(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }
}
```

The assertions the gate's own answers go through are `assertSudoRequired()` for the redirect and `assertSudoForbidden()` for the 403, so an app that binds its own `RespondToSudoRequired` redefines those two.

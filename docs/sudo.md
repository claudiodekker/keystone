# Sudo

Sudo is a short grant on a signed-in session. It says the person at the keyboard proved who they are a moment ago, which is what a change to how the account signs in should rest on. A session has sudo for 15 minutes after its sign-in, unless the user ends it sooner. The grant is bound to the network the sign-in came from.

Keystone keeps the grant in the session, next to the sign-in itself, so it needs no table and no middleware of yours.

## When a session has it

Every sign-in that proves the account brings sudo: a first factor that signs in on its own, a passed [challenge](challenge.md), and the last step of an [enrollment](enrollment.md). Keystone records `sudo.granted` with the reason `keystone.sign_in` each time (see [Security events](security-events.md)).

A sign-in restored by a [remember-me cookie](remember-me.md#coming-back) proves nothing, so it brings no sudo and records no `sudo.granted`. That also holds when the cookie restores a session that outlived its [absolute lifetime](configuration.md#session-lifetime): the restored session starts without sudo, even when the sign-in before it was minutes old.

A session holds at most one grant. Whenever a session is held, signed in, restored or signed out, its id is rotated and its grant is dropped with it, so a grant never outlives the sign-in that brought it. One change keeps the grant: when a user ends their other sessions, their own session stays signed in and keeps its sudo.

## The lifetime

`keystone.sudo.lifetime_seconds` sets how long a grant lasts, counted from the sign-in that brought it. The default is 15 minutes. To keep it for 5 minutes:

```php
'sudo' => [
    'lifetime_seconds' => 300,
],
```

Nothing extends a grant. A busy session loses sudo at the same second as an idle one. The setting is read when the grant is checked, so changing it applies to the grants sessions already hold. It must be a whole number of at least 1, or your app doesn't boot (see [Boot checks](configuration.md#boot-checks)).

## The subnet

A grant is bound to the subnet the sign-in came from, so it can be told apart from the same session cookie used on another network. The subnet is the first 24 bits of an IPv4 address and the first 64 bits of an IPv6 address:

| The sign-in came from | The grant is bound to |
|---|---|
| `203.0.113.77` | `203.0.113.0/24` |
| `::ffff:203.0.113.77` (IPv4 written as IPv6) | `203.0.113.0/24` |
| `2001:db8:0:1:aaaa:bbbb:cccc:dddd` | `2001:db8:0:1::/64` |

Keystone reads the address Laravel resolves for the request, as `$request->ip()` does. Behind a load balancer, configure Laravel's trusted proxies, or every sign-in appears to come from the balancer and every grant is bound to its subnet.

When the address isn't an IP address at all, the sign-in still completes, but it brings no sudo and records no `sudo.granted`. A grant is never made without a subnet.

Only the grant is bound. The session itself follows the user from one network to another, as it did before.

## Ending it

A user who is done with their changes can end sudo before it runs out, for example on a computer they share. The published `SudoController` does it on the `sudo.end` route, `DELETE /auth/sudo`. A button on a page of your own:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { end } from '@/routes/sudo';

defineProps<{ status: string | null }>();
</script>

<template>
    <p v-if="status">{{ status }}</p>

    <Link :href="end()" as="button">End sudo</Link>
</template>
```

Keystone then drops the grant, rotates the session id, records `sudo.revoked` and flashes the status `sudo-revoked`. The user stays signed in. `sendSudoEnded()` in your `app/Http/Controllers/Auth/SudoController.php` sends them back to the page they came from, or to `/` when the request names none:

```php
protected function sendSudoEnded(Request $request): RedirectResponse
{
    return back(fallback: '/');
}
```

The page they land on reads the flashed status and passes its translated message along, as the sign-in page does:

```php
use ClaudioDekker\Keystone\Status;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController
{
    public function show(Request $request): Response
    {
        return Inertia::render('settings/Security', [
            'status' => Status::flashed($request)?->label(),
        ]);
    }
}
```

The message is `keystone::messages.status.sudo-revoked`, and you can translate it in `lang/vendor/keystone`.

Ending sudo is safe to repeat. A request from a session whose grant already ended or ran out answers the same way and rotates the session id again, but records no `sudo.revoked`, because nothing was revoked. A guest is redirected to sign in. The route counts against the `change` [request limit](rate-limiting.md), 10 a minute by default, and its responses carry the [hardening headers](hardening.md#headers).

## Testing

Keystone's AppTests sign in, end sudo and check the session stays signed in on a new id with `sudo.revoked` recorded. They check your response through `Tests\Keystone\Assertions\SudoAssertions`. If you change what `sendSudoEnded()` returns, redefine `assertSudoEnded()` there:

```php
namespace Tests\Keystone\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions as KeystoneSudoAssertions;
use Illuminate\Testing\TestResponse;

trait SudoAssertions
{
    use KeystoneSudoAssertions;

    public function assertSudoEnded(TestResponse $response): void
    {
        $response->assertRedirectToRoute('settings.security');
    }
}
```

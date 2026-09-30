# Operator commands

Keystone's commands act on accounts without their owner. Keystone doesn't check who runs them: gate them the way you gate your own admin tooling.

## Ending sessions

End every session of one account, for example after its password leaked:

```shell
php artisan keystone:end-sessions 42 --operator="jane@ops"
```

Or of every account, for example after your session store was exposed:

```shell
php artisan keystone:end-sessions --all --operator="jane@ops"
```

In production `--all` asks before it runs; pass `--force` to skip the question in a script.

Either way the sessions end on their next request, on every session driver: Keystone moves a counter on the account that every session is stamped with, rather than deleting session rows.

Each run records a `sessions.terminated` [security event](security-events.md) with actor `operator` and the `--operator` you give, cut to 64 characters. With one account the event goes on that account's audit trail and [alerts its owner](security-alerts.md). With `--all` it is logged once, about nobody, with reason `keystone.every_account`, rather than added to every account's trail, and alerts nobody.

To end one account's sessions without alerting its owner, for example because you are on the phone with them, pass `--no-alert`. The event is still recorded:

```shell
php artisan keystone:end-sessions 42 --operator="jane@ops" --no-alert
```

### From an admin panel

Call the Actions the command runs. Inject them where you use them, for example in a controller method:

```php
use ClaudioDekker\Keystone\Actions\EndEverySession;
use ClaudioDekker\Keystone\Actions\EndSessions;

public function destroy(Request $request, User $user, EndSessions $endSessions)
{
    $endSessions->handle($user, operator: $request->user()->email);

    // Or without alerting the owner:
    $endSessions->handle($user, operator: $request->user()->email, alert: false);
}

$endEverySession->handle(operator: $request->user()->email);
```

When the signed-in user ends their own account's sessions with `EndSessions`, their current session stays signed in on a new session id and every other one ends. `EndEverySession` ends the current session too.

## Suspending accounts

Suspend an account to bar it from signing in, for example while you look into abuse:

```shell
php artisan keystone:suspend 42 --operator="jane@ops"
```

Its sessions end on their next request, and it can't sign in with any credential until you unsuspend it. A suspended account's sign-in is refused exactly like a wrong credential, so the person signing in can't tell it was suspended. It keeps its addresses, so no other account can take them in the meantime.

Unsuspend it to let it sign in again:

```shell
php artisan keystone:unsuspend 42 --operator="jane@ops"
```

Unsuspending doesn't bring back the sessions that suspending ended.

Each command records `account.suspended` or `account.unsuspended` [security events](security-events.md) with actor `operator` and the `--operator` you give, and [alerts the owner](security-alerts.md). Suspending an account that is already suspended, or unsuspending one that isn't, is refused: the command fails and nothing is recorded.

### From an admin panel

Call the Actions the commands run:

```php
use ClaudioDekker\Keystone\Actions\SuspendAccount;
use ClaudioDekker\Keystone\Actions\UnsuspendAccount;

$suspendAccount->handle($user, operator: $request->user()->email);

$unsuspendAccount->handle($user, operator: $request->user()->email);
```

They refuse an account already in the state they would put it in: `SuspendAccount` throws `ClaudioDekker\Keystone\Exceptions\AlreadySuspended`, and `UnsuspendAccount` throws `ClaudioDekker\Keystone\Exceptions\NotSuspended`.

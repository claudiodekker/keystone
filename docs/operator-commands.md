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

Each run records a `sessions.terminated` [security event](security-events.md) with actor `operator` and the `--operator` you give, cut to 64 characters. With one account the event goes on that account's audit trail. With `--all` it is logged once, about nobody, with reason `keystone.every_account`, rather than added to every account's trail.

### From an admin panel

Dispatch the jobs the command runs:

```php
use ClaudioDekker\Keystone\Jobs\EndEverySession;
use ClaudioDekker\Keystone\Jobs\EndSessions;

EndSessions::dispatch($user, operator: $request->user()->email);

EndEverySession::dispatch(operator: $request->user()->email);
```

When the signed-in user ends their own account's sessions with `EndSessions::dispatchSync()`, their current session stays signed in on a new session id and every other one ends. `EndEverySession` ends the current session too.

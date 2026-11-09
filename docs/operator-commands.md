# Operator commands

Operators look after accounts from outside the app: from a terminal on your servers, or from an admin panel your app adds. Keystone gives them an Artisan command and a job for each task. It does not decide who may run them. Anyone who can run Artisan on your servers can run the commands, and your app decides who may dispatch the jobs.

## Ending sessions

End every session of one account when it may be in the wrong hands, such as after a stolen laptop:

    php artisan keystone:end-sessions 42 --operator="Margaret Hamilton"

`42` is the account's id. Each of its sessions ends on its next request, on every session driver, cookie sessions included. The browser is sent to sign in again, and the sign-in page says "Your session has ended. Please sign in again." Sessions of other accounts carry on.

End every session of every account, yours included, with `--all`:

    php artisan keystone:end-sessions --all --operator="Margaret Hamilton"

Keystone mails the account's owner an [alert](security-events.md#alerts) that an administrator signed them out. Pass `--no-alert` to end the sessions without one, such as when you tell the owner yourself:

    php artisan keystone:end-sessions 42 --operator="Margaret Hamilton" --no-alert

Ending every account's sessions with `--all` alerts nobody.

`--operator` names who is ending the sessions, and it is required. Keystone records it on the security event, cut to 64 characters, with control characters replaced by spaces. Use a name your team will recognise in the logs, such as an email address.

The account is found by its id whatever global scopes your user model has, so an account a scope hides still has its sessions ended.

### From your app

An admin panel dispatches a job instead. `EndSessions` ends one account's sessions, and `EndAllSessions` ends every account's. Authorize the request in your app first, because the job ends the sessions of whatever account it is given:

```php
use App\Models\User;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserSessionsController
{
    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('end-sessions', $user);

        EndSessions::dispatch($user, operator: $request->user()->email);

        return back();
    }
}
```

Chain `withoutAlert()` to end the sessions without mailing the owner:

```php
EndSessions::dispatch($user, operator: $request->user()->email)->withoutAlert();
```

```php
use ClaudioDekker\Keystone\Jobs\EndAllSessions;

EndAllSessions::dispatch(operator: $request->user()->email);
```

Both jobs can be queued or run with `dispatchSync()`. A queued `EndSessions` whose account was deleted before it ran is dropped. When a job runs inside a database transaction that rolls back, no session ends and nothing is recorded.

Ending your own account's sessions, or every account's, ends the session you are using too.

### How it works

Every account has a credential epoch, a counter in the `credential_epoch` column Keystone adds to your users table. Each sign-in stamps the session with the account's epoch at that moment. Ending sessions moves the epoch, and the `keystone` guard ends any session stamped with another epoch the next time something asks who is signed in. It reads the epoch fresh from the database on every such request, past your user model's global scopes.

Because the check runs when the user is resolved, a page that never asks who is signed in keeps an ended session until one that does. Sessions from before Keystone carry no epoch, so they end on their first request.

Keep the epoch out of your own writes. The `HasKeystone` trait on your user model leaves `credential_epoch` out of the model's array form and refuses to mass-assign it, even while mass assignment is unguarded, so a request can't set an account's epoch back to the one an ended session was signed in on.

### What gets recorded

Ending one account's sessions records `sessions.terminated` for that account, with actor `operator` and the operator's name in the `operator` field, and alerts its owner unless you passed `--no-alert` or chained `withoutAlert()`. Ending every account's sessions records one `sessions.terminated` about nobody, with reason `keystone.every_account`, as a log line only. Each ended session then records `session.ended` with reason `keystone.epoch` when it makes its next request. [Security events](security-events.md) lists every field.

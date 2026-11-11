# Operator commands

Operators look after accounts from outside the app: from a terminal on your servers, or from an admin panel your app adds. Keystone gives them an Artisan command and a job for each task. It does not decide who may run them. Anyone who can run Artisan on your servers can run the commands, and your app decides who may dispatch the jobs.

## Ending sessions

End every session of one account when it may be in the wrong hands, such as after a stolen laptop:

    php artisan keystone:end-sessions 42 --operator="Margaret Hamilton"

`42` is the account's id. Each of its sessions ends on its next request, on every session driver, cookie sessions included. The browser is sent to sign in again, and the sign-in page says "Your session has ended. Please sign in again." Its [remember-me](remember-me.md) cookies stop signing it in too. Sessions of other accounts carry on.

End every session of every account, yours included, with `--all`:

    php artisan keystone:end-sessions --all --operator="Margaret Hamilton"

Keystone mails the account's owner an [alert](security-events.md#alerts) that an administrator signed them out. Pass `--no-alert` to end the sessions without one, such as when you tell the owner yourself:

    php artisan keystone:end-sessions 42 --operator="Margaret Hamilton" --no-alert

Ending every account's sessions with `--all` alerts nobody.

Ending an account's sessions also makes it forget every browser it signed in from, so a browser holding a stolen [device cookie](security-events.md#new-devices) stops counting apart, and the owner's next sign-in from each browser alerts as new. `--all` forgets every account's browsers.

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

Every account has a credential epoch, a counter in the `credential_epoch` column Keystone adds to your users table. Each sign-in stamps the session, and the remember-me token it issues, with the account's epoch at that moment. Ending sessions moves the epoch, and the `keystone` guard ends any session stamped with another epoch the next time something asks who is signed in. It reads the epoch fresh from the database on every such request, past your user model's global scopes.

Because the check runs when the user is resolved, a page that never asks who is signed in keeps an ended session until one that does. Sessions from before Keystone carry no epoch, so they end on their first request.

Keep the epoch out of your own writes. The `HasKeystone` trait on your user model leaves `credential_epoch` out of the model's array form and refuses to mass-assign it, even while mass assignment is unguarded, so a request can't set an account's epoch back to the one an ended session was signed in on.

### What gets recorded

Ending one account's sessions records `sessions.terminated` for that account, with actor `operator` and the operator's name in the `operator` field, and alerts its owner unless you passed `--no-alert` or chained `withoutAlert()`. Ending every account's sessions records one `sessions.terminated` about nobody, with reason `keystone.every_account`, as a log line only. Each ended session then records `session.ended` with reason `keystone.epoch` when it makes its next request. [Security events](security-events.md) lists every field.

## Suspending accounts

Suspend an account to bar it from signing in while you look into it, without freeing its email addresses for anyone else:

    php artisan keystone:suspend 42 --operator="Margaret Hamilton"

`42` is the account's id. Every session of the account ends on its next request, the way [ending sessions](#ending-sessions) ends them. While the account is suspended, every sign-in to it is refused with the same answer a wrong password gets, even with the right one, so the refusal doesn't tell anyone the account is suspended. That right password still counts against the account's failed-attempt limit. Its addresses stay its own, so no other account signs in with them or takes them over.

Lift the suspension to let the account sign in again with the credentials it already had:

    php artisan keystone:unsuspend 42 --operator="Margaret Hamilton"

Lifting a suspension ends no session, since the account has none left.

Suspending an account that is already suspended, or lifting the suspension of one that isn't, changes nothing, and the command says so and fails. `--operator` is required, as it is for ending sessions, and the account is found by its id whatever global scopes your user model has.

### From your app

An admin panel dispatches `SuspendAccount` or `UnsuspendAccount`. Authorize the request in your app first, because each job acts on whatever account it is given. A job whose account is already in that state throws `AccountChangeRefusedException`, so catch it when you run the job with `dispatchSync()`:

```php
use App\Models\User;
use ClaudioDekker\Keystone\Exceptions\AccountChangeRefusedException;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserSuspensionController
{
    public function store(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('suspend', $user);

        try {
            SuspendAccount::dispatchSync($user, operator: $request->user()->email);
        } catch (AccountChangeRefusedException $exception) {
            return back()->withErrors(['user' => $exception->getMessage()]);
        }

        return back();
    }
}
```

A queued job whose account was deleted before it ran is dropped. When a job runs inside a database transaction that rolls back, nothing changes and nothing is recorded.

### How it works

Suspending sets the `suspended_at` column Keystone adds to your users table, and moves the account's [credential epoch](#how-it-works) so its sessions end. The `keystone` guard reads the suspension together with the epoch on every request that asks who is signed in, past your user model's global scopes, so a session of a suspended account ends even if it was somehow on the account's epoch. The `HasKeystone` trait leaves `suspended_at` out of the model's array form and refuses to mass-assign it, so a request can't lift its own account's suspension.

### What gets recorded

Suspending records `account.suspended`, and lifting the suspension records `account.unsuspended`, both with actor `operator` and the operator's name in the `operator` field. Each mails the account's owner an [alert](security-events.md#alerts). Set the type's slot in `notifications` to `null` to stop it.

## Pruning

Keystone deletes what it keeps past its retention or lifetime every night with `keystone:prune`. Today that is every [known device](security-events.md#new-devices) no account signed in from within `retention.known_devices_seconds`, and every [remember-me](remember-me.md) token past its expiry. Keystone schedules the command itself, daily at midnight on one server, so your app registers nothing. It needs Laravel's scheduler running, as Laravel's [scheduling docs](https://laravel.com/docs/scheduling#running-the-scheduler) describe, and a cache store every server shares for `onOneServer()`.

Run it by hand at any time:

    php artisan keystone:prune

A browser unseen for the retention counts as new whether or not the prune has deleted its row yet, so a late or missed run never changes who gets an alert.

## Abandoned challenges

Keystone alerts the owner of an account whose [challenge](security-events.md#abandoned-challenges) a browser it doesn't know left unanswered with `keystone:alert-abandoned-challenges`. Keystone schedules the command itself, every 5 minutes on one server and never overlapping itself, so your app registers nothing. Like the prune, it needs the scheduler running and a shared cache store. It runs without a queue worker, though the alerts it sends are queued like any other.

Run it by hand at any time:

    php artisan keystone:alert-abandoned-challenges

It records `challenge.abandoned` for every challenge left 7 minutes or more, sends each account one alert about all of its own, and deletes them. A late run sends the alert late, and a missed run leaves the rows for the next. A run that died without releasing its overlap lock holds the next ones off for at most 10 minutes.

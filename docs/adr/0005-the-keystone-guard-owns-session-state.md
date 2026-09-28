# The keystone guard owns session state

Core registers a `keystone` guard driver, a subclass of Laravel's `SessionGuard`, and the app's guard uses it. On every request it reads the account's credential epoch, owes state and disabled, invalidated or suspended stamps in one uncached query that bypasses the model's global scopes, and ends any session stamped with an older epoch or belonging to an account that can no longer sign in. It is the only code that rotates the session id, and every `SessionGuard` method that takes credentials or creates a session (`attempt`, `validate`, `once`, `onceUsingId`, `login`, `loginUsingId`, `logoutOtherDevices` and those built on them) signs nobody in.

Checking inside the guard, rather than in middleware or listeners, means a route that drops middleware or an app that forgets a listener still can't keep a dead session alive. Resting session ending on a per-account epoch, rather than deleting session rows, works on every session driver, including cookie sessions a client can replay, and needs no session index.

## Consequences

- Sessions and `remember_web_*` cookies from before Keystone carry no epoch stamp and end on their first request, so every user signs in again at cutover.
- `Auth::attempt()`, `auth.basic` and `Auth::login()` stop working in the app. Anything that signed users in that way must go through Keystone. Tests still sign a user in with `actingAs()`, which sets the user for the test's requests without starting a session.
- Each authenticated request costs one primary-key read of the user row, the same as Laravel's own guard.
- Ending a session invalidates the whole session, so in an app with several guards on one session, a dead Keystone session signs the user out of the others too.
